<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use SecureAccess\Models\AuthorityRevocationEvidence;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\SecureAccess;
use SecureAccess\Services\AuthorityReviewService;
use SecureAccess\Services\AuthorityRevocationEvidenceService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuthorityRevocationEvidenceController extends Controller
{
    public function store(Request $request, TeamAuthorityDocument $document, AuthorityRevocationEvidenceService $evidence): RedirectResponse
    {
        $actor = $this->user();
        abort_unless(app(AuthorityReviewService::class)->canView($actor, $document), 404);
        $data = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'comments' => ['nullable', 'string', 'max:10000'],
        ]);
        $submission = $evidence->submit($actor, $document, $data, $request->file('document'));

        return redirect()->route('secure-access.authority.evidence.show', $submission)
            ->with('status', __('Evidence submitted for independent review. No access permissions have changed.'));
    }

    public function show(AuthorityRevocationEvidence $evidence, AuthorityRevocationEvidenceService $service): Response
    {
        $actor = $this->user();
        abort_unless(app(AuthorityReviewService::class)->canView($actor, $evidence->document), 404);
        $service->audit($evidence, $actor, 'revocation_evidence_viewed');
        $canViewHistory = $service->canReview($actor, $evidence);

        return response()->view('secure-access::authority.evidence', [
            'evidence' => $evidence,
            'canReview' => $evidence->status === 'pending' && $service->canReview($actor, $evidence),
            'canViewHistory' => $canViewHistory,
            'history' => $canViewHistory ? ($evidence->review_history ?? []) : [],
        ])->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function download(AuthorityRevocationEvidence $evidence, AuthorityRevocationEvidenceService $service): StreamedResponse
    {
        $actor = $this->user();
        abort_unless(app(AuthorityReviewService::class)->canView($actor, $evidence->document), 404);
        abort_unless(Storage::disk('local')->exists($evidence->path), 404);
        $service->audit($evidence, $actor, 'revocation_evidence_downloaded');

        return Storage::disk('local')->download($evidence->path, $evidence->file_name, [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(Request $request, AuthorityRevocationEvidence $evidence, AuthorityRevocationEvidenceService $service): RedirectResponse
    {
        $service->review($this->user(), $evidence, $request->all());

        return redirect()->route('secure-access.authority.evidence.show', $evidence)
            ->with('status', __('Evidence review recorded.'));
    }

    protected function user(): Model
    {
        $user = SecureAccess::guard()->user();
        abort_unless($user instanceof Model, 403);

        return $user;
    }
}
