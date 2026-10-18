<?php

namespace SecureAccess\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SecureAccess\Models\TeamAuthorityDocument;

class AuthorityOriginalStorage
{
    public function disk(): FilesystemAdapter
    {
        return Storage::build([
            'driver' => 'local',
            'root' => storage_path('authority-documents'),
            'visibility' => 'private',
            'throw' => true,
        ]);
    }

    public function validate(UploadedFile $file): void
    {
        Validator::make(['document' => $file], [
            'document' => ['required', 'file', 'extensions:pdf,jpg,jpeg,png',
                'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:10240'],
        ])->validate();
        $expected = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        $extension = strtolower($file->getClientOriginalExtension());
        if (($expected[$extension] ?? null) !== $file->getMimeType()) {
            throw ValidationException::withMessages(['document' => __('The file contents must match its extension.')]);
        }
    }

    public function filename(Model $representative, Model $ward, string $type, int $revision, Carbon $uploadedAt, string $extension): string
    {
        $label = match ($type) {
            'guardianship' => 'Guardianship',
            'conservatorship' => 'Conservatorship',
            'power_of_attorney' => 'POA',
            default => throw new \InvalidArgumentException('Unsupported authority type.'),
        };

        return $uploadedAt->copy()->timezone(config('app.timezone', 'UTC'))->format('Y-m-d')
            .' - '.$this->lastName($representative).' & '.$this->lastName($ward)
            .' - '.$label.' - Rev'.$revision.'.'.$extension;
    }

    public function path(string $wardId, string $type, string $filename): string
    {
        if (! in_array($type, TeamAuthorityDocument::TYPES, true)
            || basename($filename) !== $filename || str_contains($filename, '\\')) {
            throw new \InvalidArgumentException('Invalid original document path.');
        }

        return 'ward-'.bin2hex($wardId).'/'.$type.'/'.$filename;
    }

    public function canRead(TeamAuthorityDocument $document): bool
    {
        if (! $document->ward_id || ! $document->revision
            || $document->path !== $this->path($document->ward_id, $document->type, $document->file_name)) {
            return false;
        }
        $root = realpath(storage_path('authority-documents'));
        $absolute = $this->disk()->path($document->path);
        $actual = realpath($absolute);

        return $root !== false && $actual !== false
            && ! is_link(storage_path('authority-documents'))
            && ! is_link(dirname(dirname($absolute))) && ! is_link(dirname($absolute)) && ! is_link($absolute)
            && str_starts_with($actual, $root.DIRECTORY_SEPARATOR) && is_file($actual);
    }

    public function store(UploadedFile $file, string $path): void
    {
        $source = fopen($file->getRealPath(), 'rb');
        if ($source === false) {
            throw new \RuntimeException('Original document could not be read.');
        }
        try {
            $this->writeExclusive($source, $path);
        } finally {
            fclose($source);
        }
    }

    public function writeExclusive($source, string $path): void
    {
        if (! preg_match('~^ward-[a-f0-9]+/(guardianship|conservatorship|power_of_attorney)/[^/\\\\]+$~u', $path)
            || str_contains($path, '..')) {
            throw new \InvalidArgumentException('Invalid original document path.');
        }
        $absolute = $this->disk()->path($path);
        $directory = dirname($absolute);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Private original directory could not be created.');
        }
        $root = realpath(storage_path('authority-documents'));
        if ($root === false || ! str_starts_with(realpath($directory).DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR)
            || is_link(storage_path('authority-documents'))
            || is_link(dirname($directory)) || is_link($directory)) {
            throw new \RuntimeException('Unsafe original storage directory.');
        }
        $target = fopen($absolute, 'xb');
        if ($target === false) {
            throw new \RuntimeException('An original already exists; it will not be overwritten.');
        }
        try {
            chmod($absolute, 0600);
            if (stream_copy_to_stream($source, $target) === false || ! fflush($target)) {
                throw new \RuntimeException('Original document could not be stored.');
            }
        } catch (\Throwable $exception) {
            fclose($target);
            unlink($absolute);
            throw $exception;
        }
        fclose($target);
    }

    protected function lastName(Model $person): string
    {
        $name = trim((string) $person->getAttribute('last_name'));
        if ($name === '') {
            $parts = preg_split('/\s+/u', trim((string) $person->getAttribute('name')));
            $name = (string) end($parts);
        }
        $name = preg_replace('/[^\p{L}\p{N} _\'-]/u', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '', " .-_");

        return mb_strcut($name !== '' ? $name : 'Unknown', 0, 60, 'UTF-8');
    }
}
