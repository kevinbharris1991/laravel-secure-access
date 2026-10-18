<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\SecureAccess;
use SecureAccess\Services\AuthorityOriginalStorage;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('secure_access_team_authority_documents', 'revision')) {
            Schema::table('secure_access_team_authority_documents', function (Blueprint $table) {
                $table->string('ward_id')->nullable();
                $table->unsignedInteger('revision')->nullable();
                $table->unique(['ward_id', 'type', 'revision'], 'satad_ward_type_revision_unique');
            });
        }

        $storage = app(AuthorityOriginalStorage::class);
        TeamAuthorityDocument::query()->whereNull('revision')->orderBy('id')->each(function ($document) use ($storage) {
            $relationship = $document->relationship;
            $wardId = $relationship->self_advocate_id;
            $revision = (int) DB::table('secure_access_team_authority_documents')
                ->where('ward_id', $wardId)->where('type', $document->type)->max('revision') + 1;
            $ward = SecureAccess::newUserQuery()->findOrFail($wardId);
            $representative = SecureAccess::newUserQuery()->findOrFail($relationship->team_member_id);
            $extension = strtolower(pathinfo($document->file_name, PATHINFO_EXTENSION));
            if (! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
                throw new RuntimeException('An existing authority original has an unsupported extension.');
            }
            $filename = $storage->filename($representative, $ward, $document->type, $revision, $document->created_at, $extension);
            $path = $storage->path($wardId, $document->type, $filename);
            $source = Storage::disk('local')->readStream($document->path);
            if (! is_resource($source)) {
                throw new RuntimeException('An existing authority original is missing; restore it before migrating.');
            }
            try {
                if ($storage->disk()->exists($path)) {
                    $originalHash = hash_init('sha256');
                    hash_update_stream($originalHash, $source);
                    $existingHash = hash_file('sha256', $storage->disk()->path($path));
                    if (! hash_equals(hash_final($originalHash), $existingHash)) {
                        throw new RuntimeException('A different original already exists at the revision path.');
                    }
                } else {
                    $storage->writeExclusive($source, $path);
                }
            } finally {
                fclose($source);
            }
            DB::table('secure_access_team_authority_documents')->where('id', $document->getKey())->update([
                'ward_id' => $wardId, 'revision' => $revision, 'path' => $path, 'file_name' => $filename,
            ]);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Original revision migration is irreversible; restore the database and private storage backup together.');
    }
};
