<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use SecureAccess\Authorization\ScopeContext;
use SecureAccess\Camp\GuestMeals;
use SecureAccess\Camp\Modules;
use SecureAccess\Models\CampRecord;
use SecureAccess\SecureAccess;

class GuestPortalController extends CampController
{
    public function index(Request $request, string $module = 'guest'): View
    {
        $user = SecureAccess::guard()->user();
        $scope = new ScopeContext(ownerId: $user?->getAuthIdentifier());
        foreach (['meal_plans.read_public', 'special_meals.read_own', 'activity_commitments.read_own', 'activities.read_public', 'events.read_public'] as $permission) {
            Gate::forUser($user)->authorize('secure-access', [$permission, $scope]);
        }

        $specials = $this->query('special_meals', 'special_meals.read_own', $user)->get();
        $commitments = $this->query('activity_commitments', 'activity_commitments.read_own', $user)->get();
        $this->audit('special_meals', 'read', null);
        $this->audit('activity_commitments', 'read', null);
        $meals = GuestMeals::present(
            $this->query('meal_plans', 'meal_plans.read_public', $user)->get(),
            $specials,
        );
        $opportunities = [];
        $canCommit = [];
        foreach (['activities', 'events'] as $module) {
            $opportunities[$module] = $this->query($module, $module.'.read_public', $user)->get();
            $canCommit[$module] = Gate::forUser($user)->allows('secure-access', ['activity_commitments.create_own', $scope])
                && Modules::permission($user, $module, 'commit') !== null;
        }

        return view('secure-access::camp.guest', [
            'meals' => $meals,
            'opportunities' => $opportunities,
            'commitments' => $commitments,
            'canCommit' => $canCommit,
        ]);
    }

    public function commit(Request $request, string $module, int $record): RedirectResponse
    {
        abort_unless(in_array($module, ['activities', 'events'], true), 404);
        $user = SecureAccess::guard()->user();
        $scope = new ScopeContext(ownerId: $user?->getAuthIdentifier());
        Gate::forUser($user)->authorize('secure-access', ['activity_commitments.create_own', $scope]);
        Gate::forUser($user)->authorize('secure-access', [$module.'.read_public', $scope]);

        DB::transaction(function () use ($user, $module, $record) {
            // Serialize enrollment for this actor, including requests for the same opportunity.
            $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $opportunity = CampRecord::where('module', $module)->where('is_public', true)
                ->lockForUpdate()->findOrFail($record);
            $permission = Modules::permission($user, $module, 'commit', $opportunity);
            abort_unless($permission !== null, 403);
            Gate::forUser($user)->authorize('secure-access', [$permission, $opportunity]);

            $existing = $this->query('activity_commitments', 'activity_commitments.read_own', $user)->get()->first(
                fn ($commitment) => ($commitment->data['target_module'] ?? null) === $module
                    && (string) ($commitment->data['target_id'] ?? '') === (string) $record
            );
            if ($existing) {
                return;
            }

            $commitment = new CampRecord;
            $commitment->forceFill([
                'module' => 'activity_commitments',
                'owner_type' => $user->getMorphClass(),
                'owner_id' => (string) $user->getKey(),
                'is_public' => false,
                'data' => [
                    'target_module' => $module,
                    'target_id' => $record,
                    'name' => $opportunity->data['name'] ?? $opportunity->data['title'] ?? '',
                    'status' => 'enrolled',
                ],
            ])->save();
            $this->audit('activity_commitments', 'create', $commitment->id);
            $this->audit($module, 'commit', $opportunity->id);
        });

        return redirect()->route('secure-access.guest.index')->with('status', __('Enrollment saved.'));
    }
}
