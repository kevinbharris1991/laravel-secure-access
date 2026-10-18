<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\AuthorityRevocationEvidence;
use SecureAccess\Models\AuthorityDepartmentShare;
use SecureAccess\SecureAccess;
use SecureAccess\Services\AuthorityReviewService;
use SecureAccess\Services\AuthorityDepartmentShareService;

class AuthorityReviewController extends Controller
{
    public function index(Request $request, AuthorityReviewService $reviews): Response
    {
        $user = $this->user();
        abort_unless($reviews->isReviewer($user), 403);
        $status = $request->query('status', 'pending');
        abort_unless(in_array($status, array_merge(['all'], TeamAuthorityDocument::STATUSES,
            TeamAuthorityDocument::REVIEW_DECISIONS, TeamAuthorityDocument::LIFECYCLE_STATUSES), true), 404);
        $documents = TeamAuthorityDocument::query()->with('relationship')
            ->when($status !== 'all', function ($query) use ($status) {
                $column = in_array($status, TeamAuthorityDocument::REVIEW_DECISIONS, true) ? 'review_decision'
                    : (in_array($status, TeamAuthorityDocument::LIFECYCLE_STATUSES, true) ? 'lifecycle_status' : 'status');
                $query->where($column, $status);
            })
            ->latest()->paginate(25)->withQueryString();
        foreach ($documents as $document) {
            $reviews->audit($document, $user, 'viewed');
        }
        $evidenceQueue = AuthorityRevocationEvidence::query()->with('document.relationship')
            ->where('status', 'pending')->latest()->paginate(25, ['*'], 'evidence_page')->withQueryString();
        foreach ($evidenceQueue as $evidence) {
            $reviews->audit($evidence->document, $user, 'revocation_evidence_viewed', ['evidence_id' => $evidence->getKey()]);
        }

        return response()->view('secure-access::authority.index', compact('documents', 'status', 'evidenceQueue'))
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function show(
        TeamAuthorityDocument $document,
        AuthorityReviewService $reviews,
        AuthorityDepartmentShareService $shares
    ): Response
    {
        $user = $this->user();
        abort_unless($reviews->canView($user, $document), 404);
        $reviews->expire($document->relationship, $user);
        $document->refresh();
        $reviews->audit($document, $user, 'viewed');
        $document->load('revocationEvidence');
        foreach ($document->revocationEvidence as $evidence) {
            $reviews->audit($document, $user, 'revocation_evidence_viewed', ['evidence_id' => $evidence->getKey()]);
        }
        $canViewHistory = $reviews->canReview($user, $document);
        $canShare = $shares->canShare($user, $document);

        return response()->view('secure-access::authority.show', [
            'document' => $document,
            'canReview' => $reviews->canReview($user, $document) && $document->relationship->ended_at === null,
            'canShare' => $canShare,
            'departmentShares' => $canShare ? AuthorityDepartmentShare::query()
                ->where('document_id', $document->getKey())->latest()->get() : collect(),
            'canResubmit' => $document->relationship->ended_at === null
                && $document->relationship->team_member_id === (string) $user->getKey()
                && in_array($document->review_decision, ['denied', 'inquired'], true)
                && in_array($document->lifecycle_status, ['active', 'inactive'], true)
                && ! TeamAuthorityDocument::query()->where('previous_document_id', $document->getKey())->exists(),
            'capabilities' => $reviews->capabilities(),
            'canViewHistory' => $canViewHistory,
            'history' => $canViewHistory ? ($document->review_history ?? []) : [],
            'revisions' => $canViewHistory ? TeamAuthorityDocument::query()
                ->where('ward_id', $document->ward_id ?? $document->relationship->self_advocate_id)->where('type', $document->type)
                ->orderByDesc('revision')->get() : collect(),
            'audits' => $canViewHistory ? $document->audits()->latest()->get() : collect(),
        ])->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function update(Request $request, TeamAuthorityDocument $document, AuthorityReviewService $reviews): RedirectResponse
    {
        if ($request->has('lifecycle_status')) {
            return $this->lifecycle($request, $document, $reviews);
        }
        $user = $this->user();
        abort_unless($reviews->canReview($user, $document), 403);
        $reviews->review($user, $document, $request->all());

        return redirect()->route('secure-access.authority.show', $document)->with('status', __('Review recorded.'));
    }

    public function lifecycle(Request $request, TeamAuthorityDocument $document, AuthorityReviewService $reviews): RedirectResponse
    {
        $user = $this->user();
        abort_unless($reviews->canReview($user, $document), 403);
        $reviews->setLifecycle($user, $document, $request->all());

        return redirect()->route('secure-access.authority.show', $document)->with('status', __('Lifecycle updated.'));
    }

    protected function user(): Model
    {
        $user = SecureAccess::guard()->user();
        abort_unless($user instanceof Model, 403);

        return $user;
    }
}
