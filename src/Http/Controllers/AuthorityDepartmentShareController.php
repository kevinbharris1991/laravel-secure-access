<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use SecureAccess\Authorization\AccessControl;
use SecureAccess\Models\AuthorityDepartmentShare;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\SecureAccess;
use SecureAccess\Services\AuthorityDepartmentShareService;
use SecureAccess\Services\AuthorityReviewService;

class AuthorityDepartmentShareController extends Controller
{
    public function store(
        Request $request,
        TeamAuthorityDocument $document,
        AuthorityDepartmentShareService $shares,
        AuthorityReviewService $reviews
    ): RedirectResponse {
        abort_unless($reviews->canReview($this->user(), $document), 403);
        $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $file = $request->file('document');
        abort_unless($file instanceof UploadedFile, 422);

        $share = $shares->share($this->user(), $document, $request->all(), $file);
        $shares->notifyDepartment($share);

        return redirect()->route('secure-access.authority.show', $document)
            ->with('status', __('Redacted authority information shared with the department.'));
    }

    public function index(
        string $department,
        AuthorityDepartmentShareService $shares,
        AuthorityReviewService $reviews
    ): Response {
        $user = $this->user();
        $permission = $shares->permission($department);
        abort_unless(app(AccessControl::class)->allows($user, $permission), 403);

        $items = AuthorityDepartmentShare::query()->where('department', $department)
            ->with('document.relationship')->latest()->paginate(25)->withQueryString();
        $visible = $items->getCollection()->filter(function (AuthorityDepartmentShare $share) use ($user, $shares, $reviews) {
            $reviews->expire($share->document->relationship, $user);
            $share->load('document.relationship');
            if (! $shares->canRead($user, $share)) {
                return false;
            }
            $reviews->audit($share->document, $user, 'department_share_viewed', [
                'share_id' => $share->getKey(), 'department' => $share->department,
            ]);

            return true;
        })->values();
        $items->setCollection($visible);

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
        $share->load('document.relationship');
        abort_unless($shares->canAccessDepartment($user, $share->department), 404);
        $reviews->expire($share->document->relationship, $user);
        $share->load('document.relationship');
        abort_unless($shares->canRead($user, $share), 404);
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
        $share->load('document.relationship');
        abort_unless($shares->canAccessDepartment($user, $share->department), 404);
        $reviews->expire($share->document->relationship, $user);
        $share->load('document.relationship');
        abort_unless($shares->canRead($user, $share), 404);
        abort_unless(Storage::disk('local')->exists($share->path), 404);
        $reviews->audit($share->document, $user, 'department_share_downloaded', [
            'share_id' => $share->getKey(), 'department' => $share->department,
        ]);

        return Storage::disk('local')->download($share->path, 'redacted-authority-document.'.pathinfo($share->file_name, PATHINFO_EXTENSION), [
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
