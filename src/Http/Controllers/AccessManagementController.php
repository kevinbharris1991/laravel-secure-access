<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SecureAccess\Authorization\AccessControl;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\Permission;
use SecureAccess\SecureAccess;

class AccessManagementController extends Controller
{
    public function __construct(protected AccessControl $access)
    {
    }

    public function index(): View
    {
        $user = SecureAccess::guard()->user();
        $canManageGroups = $this->access->allows($user, 'groups.manage_all');
        $canManagePermissions = $this->access->allows($user, 'permissions.manage_all');
        abort_unless($canManageGroups || $canManagePermissions, 403);

        return view('secure-access::access.index', [
            'groups' => AccessGroup::with('permissions')->orderBy('key')->get(),
            'permissions' => Permission::orderBy('key')->get(),
            'canManageGroups' => $canManageGroups,
            'canManagePermissions' => $canManagePermissions,
        ]);
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $this->requirePermission('groups.manage_all');
        AccessGroup::create($request->validate($this->groupRules()));

        return $this->redirectToIndex();
    }

    public function updateGroup(Request $request, AccessGroup $group): RedirectResponse
    {
        $this->requirePermission('groups.manage_all');
        $validated = $request->validate($this->groupRules($group));
        if ($this->isReservedGroup($group) && $validated['key'] !== $group->key) {
            throw ValidationException::withMessages(['key' => __('This group key is reserved for registration.')]);
        }
        $group->update($validated);

        return $this->redirectToIndex();
    }

    public function destroyGroup(AccessGroup $group): RedirectResponse
    {
        $this->requirePermission('groups.manage_all');
        if ($group->permissions()->exists()) {
            $this->requirePermission('permissions.manage_all');
        }
        if ($this->isReservedGroup($group)) {
            throw ValidationException::withMessages(['key' => __('This group is required for registration and cannot be deleted.')]);
        }
        $group->delete();

        return $this->redirectToIndex();
    }

    public function syncGroupPermissions(Request $request, AccessGroup $group): RedirectResponse
    {
        $this->requirePermission('groups.manage_all');
        $this->requirePermission('permissions.manage_all');
        $validated = $request->validate([
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'integer', 'distinct', Rule::exists('secure_access_permissions', 'id')],
        ]);
        $group->permissions()->sync($validated['permissions'] ?? []);

        return $this->redirectToIndex();
    }

    public function storePermission(Request $request): RedirectResponse
    {
        $this->requirePermission('permissions.manage_all');
        Permission::create($request->validate($this->permissionRules()));

        return $this->redirectToIndex();
    }

    public function updatePermission(Request $request, Permission $permission): RedirectResponse
    {
        $this->requirePermission('permissions.manage_all');
        $permission->update($request->validate($this->permissionRules($permission)));

        return $this->redirectToIndex();
    }

    public function destroyPermission(Permission $permission): RedirectResponse
    {
        $this->requirePermission('permissions.manage_all');
        $permission->delete();

        return $this->redirectToIndex();
    }

    protected function requirePermission(string $permission): void
    {
        abort_unless($this->access->allows(SecureAccess::guard()->user(), $permission), 403);
    }

    protected function isReservedGroup(AccessGroup $group): bool
    {
        return in_array($group->key, ['executive-director', 'guest'], true);
    }

    protected function groupRules(?AccessGroup $group = null): array
    {
        return [
            'key' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('secure_access_groups', 'key')->ignore($group)],
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    protected function permissionRules(?Permission $permission = null): array
    {
        return [
            'key' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('secure_access_permissions', 'key')->ignore($permission)],
            'scope' => ['required', Rule::in(['all', 'own', 'assigned'])],
        ];
    }

    protected function redirectToIndex(): RedirectResponse
    {
        return redirect()->route('secure-access.access.index')->with('status', __('Access settings saved.'));
    }
}
