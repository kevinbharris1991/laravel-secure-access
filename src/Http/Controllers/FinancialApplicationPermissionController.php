<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use SecureAccess\Models\AuthorityDepartmentShare;
use SecureAccess\Models\FinancialApplicationDecision;
use SecureAccess\SecureAccess;
use SecureAccess\Services\AuthorityDepartmentShareService;
use SecureAccess\Services\FinancialApplicationPermissionService;

class FinancialApplicationPermissionController extends Controller
{
    public function show(
        AuthorityDepartmentShare $share,
        AuthorityDepartmentShareService $shares,
        FinancialApplicationPermissionService $permissions
    ): Response {
        $actor = $this->user();
        $share = $shares->readableShare($actor, $share);
        abort_unless($share && $share->department === 'finance-accounting', 404);
        $availableCapabilities = $permissions->capabilities($actor, $share);
        $decisions = FinancialApplicationDecision::query()->where('share_id', $share->getKey())->latest('id')->get();
        foreach ($decisions as $decision) {
            $reviewer = SecureAccess::newUserQuery()->whereKey($decision->reviewer_id)->first();
            $decision->setAttribute('reviewer_name', $reviewer?->getAttribute('name') ?: $decision->reviewer_id);
        }
        // No original document, internal review history, file path or private original metadata enters this view.
        $share = (object) $share->only(['id', 'department', 'summary', 'limitations', 'revision']);

        return response()->view('secure-access::authority.finance-actions', compact('share', 'decisions', 'availableCapabilities'))
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function store(Request $request, AuthorityDepartmentShare $share, FinancialApplicationPermissionService $permissions): RedirectResponse
    {
        $permissions->decide($this->user(), $share, $request->all());

        return redirect()->route('secure-access.finance-actions.show', $share)
            ->with('status', __('Financial application decision recorded.'));
    }

    private function user(): Model
    {
        $user = SecureAccess::guard()->user();
        abort_unless($user instanceof Model, 403);

        return $user;
    }
}
