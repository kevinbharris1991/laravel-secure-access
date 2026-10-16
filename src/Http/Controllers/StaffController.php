<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SecureAccess\Authorization\AccessControl;
use SecureAccess\Camp\Audit;
use SecureAccess\Models\AccessGroup;
use SecureAccess\SecureAccess;

class StaffController extends Controller
{
    public function __construct(protected AccessControl $access)
    {
    }

    public function index(): View
    {
        abort_unless($this->allows('users.read_all') || $this->allows('executive_users.manage_all'), 403);
        $query = SecureAccess::newUserQuery();
        $this->scopeVisibleUsers($query);
        $model = $query->getModel();
        $username = config('secure-access.username_column', 'username');
        $email = config('secure-access.email_column', 'email');
        $users = $query->select(array_unique([$model->getKeyName(), 'name', $username, $email]))
            ->orderBy('name')->paginate(25);
        $groups = AccessGroup::with('permissions')->orderBy('name')->get();
        $memberships = DB::table('secure_access_memberships')
            ->where('member_type', $model->getMorphClass())
            ->whereIn('member_id', $users->pluck($model->getKeyName())->map(fn ($id) => (string) $id))
            ->get()->groupBy('member_id');
        $users->getCollection()->transform(function ($user) use ($memberships) {
            return [
                'id' => (string) $user->getKey(),
                'name' => $user->name,
                'username' => $user->getAttribute(config('secure-access.username_column', 'username')),
                'email' => $user->getAttribute(config('secure-access.email_column', 'email')),
                'group_ids' => $memberships->get((string) $user->getKey(), collect())->pluck('group_id')->all(),
            ];
        });
        Audit::record('staff.read_all', 'staff');

        return view('secure-access::camp.staff', [
            'users' => $users,
            'groups' => $groups->filter(fn ($group) => $this->canAssignGroup($group)),
            'canCreate' => $this->allows('users.create_all') || $this->allows('executive_users.manage_all'),
            'canUpdate' => $this->allows('users.update_all') || $this->allows('executive_users.manage_all'),
            'canDelete' => $this->allows('users.delete_all') || $this->allows('executive_users.manage_all'),
            'canAssign' => $this->allows('users.assign_all') || $this->allows('executive_users.manage_all'),
            'canResetPassword' => $this->allows('credentials.manage_all') || $this->allows('executive_users.manage_all'),
            'createPermission' => $this->viewPermission('users.create_all'),
            'updatePermission' => $this->viewPermission('users.update_all'),
            'deletePermission' => $this->viewPermission('users.delete_all'),
            'assignPermission' => $this->viewPermission('users.assign_all'),
            'credentialPermission' => $this->allows('credentials.manage_all') ? 'credentials.manage_all' : 'executive_users.manage_all',
            'actorId' => (string) SecureAccess::guard()->id(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->allows('users.create_all') || $this->allows('executive_users.manage_all'), 403);
        $this->normalizeGroups($request);
        $validated = $request->validate($this->rules());

        DB::transaction(function () use ($validated) {
            $this->lockGroups();
            $groupIds = $validated['group_ids'] ?? [];
            $this->authorizeGroups($groupIds);
            $executive = $this->groupsAreExecutive($groupIds);
            abort_unless($executive ? $this->allows('executive_users.manage_all') : $this->allows('users.create_all'), 403);
            abort_unless($this->allows('credentials.manage_all') || ($executive && $this->allows('executive_users.manage_all')), 403);
            $model = SecureAccess::userModel();
            $user = new $model;
            $this->fillUser($user, $validated);
            $user->save();
            $this->syncGroups($user, $groupIds);
            Audit::record('staff.create', 'staff', (string) $user->getKey(), ['group_ids' => $groupIds]);
        });

        return $this->redirectToIndex();
    }

    public function update(Request $request, string $user): RedirectResponse
    {
        abort_unless($this->allows('users.update_all') || $this->allows('executive_users.manage_all'), 403);
        DB::transaction(function () use ($request, $user) {
            $this->lockGroups();
            $target = SecureAccess::newUserQuery()->whereKey($user)->lockForUpdate()->firstOrFail();
            $this->authorizeTarget($target, 'users.update_all');
            $this->normalizeGroups($request);
            $validated = $request->validate($this->rules($target));
            $previousGroupIds = $this->groupIds($target);
            $reset = filled($validated['password'] ?? null);
            abort_if($reset && ! $this->allows('credentials.manage_all') && ! ($this->isExecutive($target) && $this->allows('executive_users.manage_all')), 403);
            if (array_key_exists('group_ids', $validated)) {
                $this->authorizeGroups($validated['group_ids']);
                $remainsExecutive = $this->groupsAreExecutive($validated['group_ids']);
                if ($this->isExecutive($target) && ! $remainsExecutive) {
                    abort_unless($this->allows('users.update_all'), 403);
                    $this->protectLastExecutive($target);
                }
                $this->syncGroups($target, $validated['group_ids']);
            }
            $this->fillUser($target, $validated);
            $target->save();
            $currentGroupIds = $this->groupIds($target);
            Audit::record('staff.update', 'staff', (string) $target->getKey(), [
                'group_ids' => $currentGroupIds, 'password_reset' => $reset,
            ]);
            if (collect($previousGroupIds)->map(fn ($id) => (string) $id)->sort()->values()->all()
                !== collect($currentGroupIds)->map(fn ($id) => (string) $id)->sort()->values()->all()) {
                Audit::record('staff.roles_updated', 'staff', (string) $target->getKey(), [
                    'previous_group_ids' => $previousGroupIds, 'group_ids' => $currentGroupIds,
                ]);
            }
            if ($reset) {
                Audit::record('credentials.reset', 'staff', (string) $target->getKey());
            }
        });

        return $this->redirectToIndex();
    }

    public function destroy(Request $request, string $user): RedirectResponse
    {
        abort_unless($this->allows('users.delete_all') || $this->allows('executive_users.manage_all'), 403);
        DB::transaction(function () use ($user) {
            $this->lockGroups();
            $target = SecureAccess::newUserQuery()->whereKey($user)->lockForUpdate()->firstOrFail();
            $this->authorizeTarget($target, 'users.delete_all');
            if ((string) $target->getKey() === (string) SecureAccess::guard()->id()) {
                throw ValidationException::withMessages(['user' => __('You cannot delete your own account.')]);
            }
            $this->protectLastExecutive($target);
            DB::table('secure_access_memberships')->where('member_type', $target->getMorphClass())
                ->where('member_id', (string) $target->getKey())->delete();
            $id = (string) $target->getKey();
            $target->delete();
            Audit::record('staff.delete', 'staff', $id);
        });

        return $this->redirectToIndex();
    }

    protected function allows(string $key): bool
    {
        $actor = SecureAccess::guard()->user();

        return $this->access->allows($actor, $key)
            || (str_starts_with($key, 'users.') && $this->access->allows($actor, 'users.manage_all'));
    }

    protected function viewPermission(string $key): string
    {
        if ($this->allows('users.manage_all')) {
            return 'users.manage_all';
        }

        return $this->allows($key) ? $key : 'executive_users.manage_all';
    }

    protected function rules(?Model $user = null): array
    {
        $model = SecureAccess::userModel();

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', Rule::unique($model, config('secure-access.username_column', 'username'))->ignore($user)],
            'email' => ['required', 'email', 'max:255', Rule::unique($model, config('secure-access.email_column', 'email'))->ignore($user)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:12', 'max:255', 'confirmed'],
            'group_ids' => [$user ? 'sometimes' : 'present', 'array'],
            'group_ids.*' => ['required', 'integer', 'distinct', Rule::exists('secure_access_groups', 'id')],
        ];
    }

    protected function fillUser(Model $user, array $validated): void
    {
        $user->setAttribute('name', $validated['name']);
        $user->setAttribute(config('secure-access.username_column', 'username'), $validated['username']);
        $email = config('secure-access.email_column', 'email');
        if ($user->exists && $user->getAttribute($email) !== $validated['email']
            && array_key_exists('email_verified_at', $user->getAttributes())) {
            $user->setAttribute('email_verified_at', null);
        }
        $user->setAttribute($email, $validated['email']);
        if (filled($validated['password'] ?? null)) {
            $user->setAttribute($user->getAuthPasswordName(), Hash::make($validated['password']));
            $user->setRememberToken(Str::random(60));
        }
    }

    protected function authorizeTarget(Model $user, string $permission): void
    {
        abort_unless($this->isExecutive($user)
            ? $this->allows('executive_users.manage_all')
            : $this->allows($permission), 403);
        abort_if($this->access->allows($user, 'permissions.manage_all') && ! $this->allows('permissions.manage_all'), 403);
    }

    protected function authorizeGroups(array $ids): void
    {
        abort_unless($this->allows('users.assign_all') || $this->allows('executive_users.manage_all'), 403);
        foreach (AccessGroup::with('permissions')->whereKey($ids)->get() as $group) {
            abort_unless($this->canAssignGroup($group), 403);
        }
    }

    protected function canAssignGroup(AccessGroup $group): bool
    {
        if ($group->key === 'executive-director' && ! $this->allows('executive_users.manage_all')) {
            return false;
        }
        foreach ($group->permissions as $permission) {
            if (in_array($permission->key, ['executive_users.manage_all', 'permissions.manage_all'], true)
                && $permission->scope === 'all' && ! $this->allows($permission->key)) {
                return false;
            }
        }

        return true;
    }

    protected function groupsAreExecutive(array $ids): bool
    {
        return AccessGroup::whereKey($ids)->where(function ($query) {
            $query->where('key', 'executive-director')->orWhereHas('permissions', fn ($permissions) => $permissions
                ->where('key', 'executive_users.manage_all')->where('scope', 'all'));
        })->exists();
    }

    protected function groupIds(Model $user): array
    {
        return DB::table('secure_access_memberships')->where('member_type', $user->getMorphClass())
            ->where('member_id', (string) $user->getKey())->pluck('group_id')->all();
    }

    protected function isExecutive(Model $user): bool
    {
        return $this->groupsAreExecutive($this->groupIds($user));
    }

    protected function scopeVisibleUsers(Builder $query): void
    {
        $model = $query->getModel();
        $executiveGroups = AccessGroup::where('key', 'executive-director')
            ->orWhereHas('permissions', fn ($permissions) => $permissions->where('key', 'executive_users.manage_all')->where('scope', 'all'))
            ->pluck('id');
        if ($this->allows('users.read_all') && $this->allows('executive_users.manage_all')) {
            return;
        }
        $query->whereIn($model->qualifyColumn($model->getKeyName()), function ($memberships) use ($model, $executiveGroups) {
            $memberships->select('member_id')->from('secure_access_memberships')
                ->where('member_type', $model->getMorphClass())->whereIn('group_id', $executiveGroups);
        }, 'and', $this->allows('users.read_all'));
    }

    protected function protectLastExecutive(Model $user): void
    {
        if (! $this->isExecutive($user)) {
            return;
        }
        $query = SecureAccess::newUserQuery()->whereKeyNot($user->getKey());
        $model = $query->getModel();
        $query->whereIn($model->qualifyColumn($model->getKeyName()), function ($memberships) use ($model) {
            $memberships->select('member_id')->from('secure_access_memberships')
                ->where('member_type', $model->getMorphClass())
                ->whereIn('group_id', AccessGroup::where('key', 'executive-director')
                    ->orWhereHas('permissions', fn ($permissions) => $permissions->where('key', 'executive_users.manage_all')->where('scope', 'all'))->select('id'));
        });
        if (! $query->exists()) {
            throw ValidationException::withMessages(['user' => __('The last executive account cannot be removed.')]);
        }
    }

    protected function lockGroups(): void
    {
        AccessGroup::orderBy('id')->lockForUpdate()->get();
    }

    protected function syncGroups(Model $user, array $ids): void
    {
        $relation = $user->morphToMany(AccessGroup::class, 'member', 'secure_access_memberships', 'member_id', 'group_id');
        $relation->sync($ids);
    }

    protected function normalizeGroups(Request $request): void
    {
        if ($request->boolean('sync_groups') && ! $request->has('group_ids')) {
            $request->merge(['group_ids' => []]);
        }
    }

    protected function redirectToIndex(): RedirectResponse
    {
        return redirect()->route('secure-access.staff.index')->with('status', __('Staff account saved.'));
    }
}
