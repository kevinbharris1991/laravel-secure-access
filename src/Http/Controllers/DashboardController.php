<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use SecureAccess\Camp\Modules;
use SecureAccess\Authorization\AccessControl;
use SecureAccess\Dashboard\Dashboard;
use SecureAccess\Dashboard\DashboardItem;
use SecureAccess\Models\AccessGroup;
use SecureAccess\SecureAccess;

class DashboardController extends Controller
{
    public function __invoke(Dashboard $dashboard): View
    {
        $user = SecureAccess::guard()->user();
        $groups = collect();

        if (config('secure-access-acl.enabled') && $user instanceof Model && $user->exists) {
            $groups = AccessGroup::query()
                ->whereExists(fn ($memberships) => $memberships->selectRaw('1')
                    ->from('secure_access_memberships')
                    ->whereColumn('secure_access_memberships.group_id', 'secure_access_groups.id')
                    ->where('member_type', $user->getMorphClass())
                    ->where('member_id', (string) $user->getKey()))
                ->orderBy('name')
                ->get();
        }

        $staffPermission = collect(['users.read_all', 'users.manage_all', 'executive_users.manage_all'])
            ->first(fn ($permission) => Gate::forUser($user)->allows('secure-access', [$permission]));

        return view('secure-access::dashboard.index', [
            'groups' => $groups,
            'staffPermission' => $staffPermission,
            'canReviewAuthority' => app(AccessControl::class)->isMember($user, 'administrative-director'),
            'canManageTeam' => in_array($user?->registration_role, ['self_advocate', 'family_caregiver', 'professional'], true),
            'campItems' => config('secure-access-acl.enabled') ? Modules::visible($user) : [],
            'items' => $dashboard->resolveFor($user, [
                'groups' => new DashboardItem('groups.manage_all', fn () => [
                    'title' => __('Manage groups'),
                    'description' => __('Manage access groups and their permissions.'),
                    'url' => route('secure-access.access.index'),
                ]),
                'permissions' => new DashboardItem('permissions.manage_all', fn () => [
                    'title' => __('Manage permissions'),
                    'description' => __('Manage the permissions available to your organization.'),
                    'url' => route('secure-access.access.index'),
                ]),
                'invitations' => new DashboardItem('invitations.create_all', fn () => [
                    'title' => __('Invite users'),
                    'description' => __('Send registration links and assign access groups.'),
                    'url' => route('secure-access.invitations.index'),
                ]),
            ]),
        ]);
    }
}
