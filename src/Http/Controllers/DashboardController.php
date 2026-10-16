<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Controller;
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

        return view('secure-access::dashboard.index', [
            'groups' => $groups,
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
