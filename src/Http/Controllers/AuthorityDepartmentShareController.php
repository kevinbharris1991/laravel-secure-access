<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use SecureAccess\Models\AuthorityDepartmentShare;
use SecureAccess\Models\DepartmentAssignment;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\SecureAccess;
use SecureAccess\Services\AuthorityDepartmentShareService;
use SecureAccess\Services\AuthorityReviewService;

class AuthorityDepartmentShareController extends Controller
{
    public function store(
        Request $request,
        TeamAuthorityDocument $document,
        AuthorityDepartmentShareService $shares
    ): RedirectResponse {
        abort_unless($shares->canManage($this->user(), $document), 403);
        $request->validate([
            'document' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:10240'],
        ]);
        $file = $request->file('document');
        abort_unless($file instanceof UploadedFile, 422);

        $shares->share($this->user(), $document, $request->all(), $file);

        return redirect()->route('secure-access.authority.show', $document)
            ->with('status', __('Departmental disclosure saved.'));
    }

    public function update(Request $request, AuthorityDepartmentShare $share, AuthorityDepartmentShareService $shares): RedirectResponse
    {
        abort_unless($shares->canManage($this->user(), $share->document), 403);
        $request->validate(['document' => ['nullable', 'file']]);
        $shares->update($this->user(), $share, $request->all(), $request->file('document'));

        return redirect()->route('secure-access.authority.show', $share->document_id);
    }

    public function destroy(AuthorityDepartmentShare $share, AuthorityDepartmentShareService $shares): RedirectResponse
    {
        $shares->remove($this->user(), $share);

        return redirect()->route('secure-access.authority.show', $share->document_id);
    }

    public function index(
        string $department,
        AuthorityDepartmentShareService $shares,
        AuthorityReviewService $reviews
    ): Response {
        $user = $this->user();
        abort_unless($shares->canAccessDepartment($user, $department), 403);

        $visible = AuthorityDepartmentShare::query()->where('department', $department)->where('status', 'published')
            ->whereHas('document.relationship', fn ($query) => $query->whereNull('ended_at')
                ->whereIn('self_advocate_id', DepartmentAssignment::query()->select('ward_id')
                    ->where('department', $department)->where('assignee_id', (string) $user->getKey())))
            ->latest()->get()->map(fn (AuthorityDepartmentShare $share) => $shares->readableShare($user, $share))
            ->filter()->values();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $items = new LengthAwarePaginator($visible->forPage($page, 25)->values(), $visible->count(), 25, $page, [
            'path' => request()->url(), 'query' => request()->query(),
        ]);
        foreach ($items as $share) {
            $reviews->audit($share->document, $user, 'department_share_viewed', [
                'share_id' => $share->getKey(), 'department' => $share->department,
            ]);
        }

        return response()->view('secure-access::authority.shares.index', compact('items', 'department'))
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function show(
        AuthorityDepartmentShare $share,
        AuthorityDepartmentShareService $shares,
        AuthorityReviewService $reviews
    ): Response {
        $user = $this->user();
        $share = $shares->readableShare($user, $share);
        abort_unless($share, 404);
        $reviews->audit($share->document, $user, 'department_share_viewed', [
            'share_id' => $share->getKey(), 'department' => $share->department,
        ]);

        return response()->view('secure-access::authority.shares.show', compact('share'))
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function download(
        AuthorityDepartmentShare $share,
        AuthorityDepartmentShareService $shares,
        AuthorityReviewService $reviews
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        $user = $this->user();
        $share = $shares->readableShare($user, $share);
        abort_unless($share, 404);
        abort_unless(Storage::disk('local')->exists($share->path), 404);
        $reviews->audit($share->document, $user, 'department_share_downloaded', [
            'share_id' => $share->getKey(), 'department' => $share->department,
        ]);

        return Storage::disk('local')->download($share->path, 'redacted-authority-document.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function user(): Model
    {
        $user = SecureAccess::guard()->user();
        abort_unless($user instanceof Model, 403);

        return $user;
    }
}
