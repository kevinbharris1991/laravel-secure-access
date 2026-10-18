<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use SecureAccess\Authorization\RepresentativeAccess;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\SecureAccess;
use SecureAccess\Services\AuthorityReviewService;

class AuthorityReviewController extends Controller
{
    public function index(Request $request, AuthorityReviewService $reviews): Response
    {
        $user = $this->user();
        abort_unless($reviews->isReviewer($user), 403);
        $status = $request->query('status', 'pending');
        abort_unless(in_array($status, array_merge(['all'], TeamAuthorityDocument::STATUSES), true), 404);
        $documents = TeamAuthorityDocument::query()->with('relationship')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest()->paginate(25)->withQueryString();
        foreach ($documents as $document) {
            $reviews->audit($document, $user, 'viewed');
        }

        return response()->view('secure-access::authority.index', compact('documents', 'status'))
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function show(TeamAuthorityDocument $document, AuthorityReviewService $reviews): Response
    {
        $user = $this->user();
        abort_unless($reviews->canView($user, $document), 404);
        $reviews->expire($document->relationship, $user);
        $document->refresh();
        $reviews->audit($document, $user, 'viewed');

        return response()->view('secure-access::authority.show', [
            'document' => $document,
            'canReview' => $reviews->canReview($user, $document) && $document->relationship->ended_at === null,
            'canResubmit' => $document->relationship->ended_at === null
                && $document->relationship->team_member_id === (string) $user->getKey()
                && in_array($document->status, ['rejected', 'needs_information'], true)
                && ! TeamAuthorityDocument::query()->where('previous_document_id', $document->getKey())->exists(),
            'capabilities' => app(RepresentativeAccess::class)->capabilities(),
            'history' => $document->review_history ?? [],
        ])->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function update(Request $request, TeamAuthorityDocument $document, AuthorityReviewService $reviews): RedirectResponse
    {
        $user = $this->user();
        abort_unless($reviews->canReview($user, $document), 403);
        $reviews->review($user, $document, $request->all());

        return redirect()->route('secure-access.authority.show', $document)->with('status', __('Review recorded.'));
    }

    protected function user(): Model
    {
        $user = SecureAccess::guard()->user();
        abort_unless($user instanceof Model, 403);

        return $user;
    }
}
