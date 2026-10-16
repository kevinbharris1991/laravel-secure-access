<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SecureAccess\Camp\Audit;
use SecureAccess\Camp\Modules;
use SecureAccess\Models\CampAssignment;
use SecureAccess\Models\CampAudit;
use SecureAccess\Models\CampRecord;
use SecureAccess\Models\Permission;
use SecureAccess\SecureAccess;

class CampController extends Controller
{
    public function index(Request $request, string $module)
    {
        $user = SecureAccess::guard()->user();
        $definition = Modules::get($module);
        $permissions = $this->permissions($user, $module);
        abort_if($permissions === [], 403);
        $subject = Modules::subject($user, $module);
        if ($definition['aggregate']) {
            abort_unless(isset($permissions['read']) || isset($permissions['export']) || isset($permissions['audit']), 403);
            $records = $this->aggregate($module, $request);
        } elseif (isset($permissions['read'])) {
            $records = $this->query($module, $permissions['read'], $user)->orderByDesc('id')->paginate(25);
            $records->getCollection()->each(fn ($record) => $this->project($record, $definition));
        } else {
            $records = new LengthAwarePaginator([], 0, 25, 1, ['path' => $request->url()]);
        }
        $this->audit($module, 'read', null);

        return view('secure-access::camp.index', compact('module', 'definition', 'records', 'permissions', 'subject'));
    }

    public function create(Request $request, string $module)
    {
        $this->authorizeAction($module, 'create');
        abort_if($module === 'activity_commitments', 403);
        $definition = Modules::get($module);
        abort_if($definition['aggregate'], 403);
        $record = null;
        $user = SecureAccess::guard()->user();
        $permissions = $this->permissions($user, $module);
        $subject = Modules::subject($user, $module);
        $messages = session('errors')?->all() ?? [];

        return view('secure-access::camp.form', compact('module', 'definition', 'record', 'permissions', 'subject', 'messages'));
    }

    public function store(Request $request, string $module)
    {
        $permission = $this->authorizeAction($module, 'create');
        abort_if($module === 'activity_commitments', 403);
        $definition = Modules::get($module);
        abort_if($definition['aggregate'], 403);
        $data = $this->validatedData($request, $module);
        $camperId = $this->camperId($request, $permission);
        $owner = $this->owner($request, $module, $permission);
        $publication = $this->publication($request, $module);
        DB::transaction(function () use ($module, $data, $camperId, $owner, $publication) {
            $user = SecureAccess::guard()->user();
            $record = new CampRecord;
            $record->module = $module;
            $record->owner_type = $owner->getMorphClass();
            $record->owner_id = (string) $owner->getKey();
            $record->camper_id = $camperId;
            $record->is_public = $publication ?? false;
            $record->data = $data;
            $this->authorizeAction($module, 'create', $record);
            $record->save();
            if ($module === 'registrations') {
                $record->camper_id = $record->id;
                $record->save();
            }
            $this->audit($module, 'create', $record->id);
            $this->publishRelated($record);
        });

        return $this->redirect($module);
    }

    public function show(Request $request, string $module, int $record)
    {
        return $this->recordView($module, $record, 'read', 'show');
    }

    public function edit(Request $request, string $module, int $record)
    {
        return $this->recordView($module, $record, 'update', 'form');
    }

    public function update(Request $request, string $module, int $record)
    {
        $record = $this->record($module, $record);
        $this->authorizeAction($module, 'update', $record);
        abort_if($module === 'activity_commitments', 403);
        $data = $module === 'work_orders'
            ? $request->validate(['status' => Modules::get($module)['fields']['status']])
            : $this->validatedData($request, $module);
        $publication = $this->publication($request, $module);
        DB::transaction(function () use ($record, $data, $module, $publication) {
            $record = CampRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->authorizeAction($module, 'update', $record);
            // Ownership and assignment identifiers are deliberately never request-writable.
            $record->data = $module === 'work_orders' ? $data + $record->data
                : $data + array_intersect_key($record->data, array_flip(['status', 'assignee_id', 'assignee_type', 'acknowledged_by']));
            if ($publication !== null) {
                $record->is_public = $publication;
            }
            $record->save();
            $this->audit($module, 'update', $record->id);
            $this->publishRelated($record);
        });

        return $this->redirect($module);
    }

    public function destroy(Request $request, string $module, int $record)
    {
        $record = $this->record($module, $record);
        $this->authorizeAction($module, 'delete', $record);
        DB::transaction(function () use ($record, $module) {
            $this->audit($module, 'delete', $record->id);
            $record->delete();
        });

        return $this->redirect($module);
    }

    public function action(Request $request, string $module, int $record, string $action)
    {
        abort_unless(in_array($action, ['assign', 'acknowledge', 'approve', 'submit', 'cancel', 'commit', 'pay'], true), 404);
        if ($action === 'commit') {
            return app(GuestPortalController::class)->commit($request, $module, $record);
        }
        $record = $this->record($module, $record);
        $this->authorizeAction($module, $action, $record);
        $assignee = null;
        if ($action === 'assign') {
            $model = SecureAccess::newUserQuery()->getModel();
            $validated = $request->validate(['assignee_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    if (! is_string($value) && ! is_int($value)) {
                        $fail('Choose one existing assignee.');
                    }
                },
                Rule::exists(get_class($model), $model->getKeyName()),
            ]]);
            $assignee = SecureAccess::newUserQuery()->find($validated['assignee_id']);
            abort_unless($assignee, 422, 'The assignee must be an existing user.');
        }
        $camperId = $module === 'rosters' && $action === 'assign'
            ? $request->validate(['camper_id' => ['required', 'integer', Rule::exists('secure_access_camp_records', 'id')->where('module', 'registrations')]])['camper_id']
            : null;
        if ($camperId !== null && ! Gate::forUser($assignee)->allows('secure-access', [
            'care_plans.read_assigned', Modules::subject($assignee, 'care_plans'),
        ])) {
            throw ValidationException::withMessages(['assignee_id' => 'Choose an authorized counselor.']);
        }
        DB::transaction(function () use ($record, $module, $action, $assignee, $camperId) {
            $record = CampRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->authorizeAction($module, $action, $record);
            $user = SecureAccess::guard()->user();
            $data = $record->data;
            if ($action === 'assign') {
                if ($camperId !== null) {
                    SecureAccess::newUserQuery()->whereKey($assignee->getKey())->lockForUpdate()->firstOrFail();
                    $counselor = DB::table('secure_access_memberships')
                        ->join('secure_access_groups', 'secure_access_groups.id', '=', 'secure_access_memberships.group_id')
                        ->where('member_type', $assignee->getMorphClass())
                        ->where('member_id', (string) $assignee->getKey())
                        ->where('secure_access_groups.key', 'counselors-dsps')->exists();
                    if (! $counselor) {
                        throw ValidationException::withMessages(['assignee_id' => 'Choose an assigned counselor or DSP.']);
                    }
                    $assignment = CampAssignment::where('user_type', $assignee->getMorphClass())
                        ->where('user_id', (string) $assignee->getKey())->where('camper_id', $camperId)->first();
                    if (! $assignment) {
                        $assignment = new CampAssignment;
                        $assignment->forceFill(['user_type' => $assignee->getMorphClass(), 'user_id' => (string) $assignee->getKey(), 'camper_id' => $camperId])->save();
                    }
                }
                $data['assignee_id'] = (string) $assignee->getKey();
                $data['assignee_type'] = $assignee->getMorphClass();
                $data['status'] = 'assigned';
            } elseif ($action === 'acknowledge') {
                $data['acknowledged_by'] = array_values(array_unique([...(array) ($data['acknowledged_by'] ?? []), $user->getMorphClass().':'.$user->getKey()]));
            } elseif ($action === 'pay') {
                $data['status'] = 'pending_payment';
            } else {
                if ($action === 'cancel' && ($data['arrival_date'] ?? '') <= now()->toDateString()) {
                    throw ValidationException::withMessages(['arrival_date' => 'Only upcoming reservations can be cancelled.']);
                }
                $data['status'] = ['approve' => 'approved', 'submit' => 'submitted', 'cancel' => 'cancelled'][$action];
            }
            $record->data = $data;
            $record->save();
            $this->audit($module, $action, $record->id);
        });

        return $this->redirect($module);
    }

    public function export(Request $request, string $module)
    {
        $this->authorizeAction($module, 'export');
        abort_unless(Modules::get($module)['aggregate'], 403);
        $rows = $this->aggregateRows($module);
        $this->audit($module, 'export', null);

        return response()->streamDownload(function () use ($rows) {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Module', 'Records']);
            foreach ($rows as $row) {
                fputcsv($output, [$row->data['module'], $row->data['records']]);
            }
            fclose($output);
        }, $module.'.csv', ['Content-Type' => 'text/csv']);
    }

    protected function recordView(string $module, int $id, string $action, string $view)
    {
        $record = $this->record($module, $id);
        $this->authorizeAction($module, $action, $record);
        abort_if($module === 'activity_commitments' && $action === 'update', 403);
        $definition = Modules::get($module);
        if ($module === 'work_orders' && $action === 'update') {
            $definition['fields'] = ['status' => $definition['fields']['status']];
        }
        $user = SecureAccess::guard()->user();
        $permissions = $this->permissions($user, $module, $record);
        $subject = Modules::subject($user, $module, $record);
        $this->audit($module, 'read', $record->id);
        $this->project($record, $definition);
        $messages = session('errors')?->all() ?? [];
        $guestName = $module === 'special_meals' && $record->owner_type === SecureAccess::newUserQuery()->getModel()->getMorphClass()
            ? SecureAccess::newUserQuery()->whereKey($record->owner_id)->value('name') : null;

        return view('secure-access::camp.'.$view, compact('module', 'definition', 'record', 'permissions', 'subject', 'messages', 'guestName'));
    }

    protected function record(string $module, int $id): CampRecord
    {
        abort_if(Modules::get($module)['aggregate'], 403);

        return CampRecord::query()->where('module', $module)->findOrFail($id);
    }

    protected function authorizeAction(string $module, string $action, ?CampRecord $record = null): string
    {
        $permission = Modules::permission(SecureAccess::guard()->user(), $module, $action, $record);
        abort_unless($permission, 403);

        return $permission;
    }

    protected function permissions($user, string $module, ?CampRecord $record = null): array
    {
        $permissions = [];
        foreach (Modules::get($module)['actions'] as $action) {
            if ($module === 'activity_commitments' && in_array($action, ['create', 'update'], true)) {
                continue;
            }
            if ($permission = Modules::permission($user, $module, $action, $record)) {
                $permissions[$action] = $permission;
            }
        }

        return $permissions;
    }

    protected function query(string $module, string $permission, $user): Builder
    {
        $query = CampRecord::query()->where('module', $module);
        $scope = Permission::where('key', $permission)->value('scope');
        if ($scope === 'own' || str_ends_with($permission, '_own')) {
            $query->where('owner_type', $user->getMorphClass())->where('owner_id', (string) $user->getKey());
        }
        if ($scope === 'assigned' || str_ends_with($permission, '_assigned')) {
            $query->whereIn('camper_id', CampAssignment::query()->select('camper_id')
                ->where('user_type', $user->getMorphClass())->where('user_id', (string) $user->getKey()));
        }
        if (str_ends_with($permission, '_public')) {
            $query->where('is_public', true);
        }

        return $query;
    }

    protected function camperId(Request $request, string $permission): ?int
    {
        $module = explode('.', $permission, 2)[0];
        if (! in_array($module, ['waivers', 'consents', 'households', 'medications', 'medication_stock', 'dosages', 'health_profiles', 'clinical_protocols', 'clinical_incidents', 'care_plans', 'allergy_alerts', 'safety_alerts', 'behavior_health_context', 'support_plans', 'behavior_strategies', 'behavior_logs', 'consultation_notes', 'daily_logs', 'incident_flags', 'participation', 'accommodations'], true)) {
            return null;
        }
        $assigned = str_ends_with($permission, '_assigned')
            || Permission::where('key', $permission)->value('scope') === 'assigned';
        $validated = $request->validate(['camper_id' => [$assigned ? 'required' : 'nullable', 'integer',
            Rule::exists('secure_access_camp_records', 'id')->where('module', 'registrations')]]);
        $id = $validated['camper_id'] ?? null;
        if ($id !== null && ($assigned || str_ends_with($permission, '_own'))) {
            $camper = CampRecord::findOrFail($id);
            $user = SecureAccess::guard()->user();
            abort_unless($assigned ? $camper->isAssignedTo($user) : $camper->isOwnedBy($user), 403);
        }

        return $id === null ? null : (int) $id;
    }

    protected function project(CampRecord $record, array $definition): void
    {
        $record->data = array_intersect_key($record->data ?? [], $definition['fields'] + array_fill_keys(['status', 'assignee_id', 'acknowledged_by'], true));
    }

    protected function owner(Request $request, string $module, string $permission)
    {
        $actor = SecureAccess::guard()->user();
        if ($module !== 'booking_balances') {
            return $actor;
        }
        abort_unless(Permission::where('key', $permission)->value('scope') === 'all', 403);
        $model = SecureAccess::newUserQuery()->getModel();
        $validated = $request->validate(['guest_id' => ['bail', 'required', function ($attribute, $value, $fail) {
            if (! is_string($value) && ! is_int($value)) {
                $fail('Choose one guest account.');
            }
        }, Rule::exists(get_class($model), $model->getKeyName())]]);
        $guest = SecureAccess::newUserQuery()->findOrFail($validated['guest_id']);
        abort_unless(DB::table('secure_access_memberships')->join('secure_access_groups', 'group_id', '=', 'secure_access_groups.id')
            ->where('member_type', $guest->getMorphClass())->where('member_id', (string) $guest->getKey())
            ->where('secure_access_groups.key', 'guest')->exists(), 422, 'Choose a guest account.');

        return $guest;
    }

    protected function publication(Request $request, string $module): ?bool
    {
        if (! in_array($module, ['meal_plans', 'activities', 'events'], true) || ! $request->has('is_public')) {
            return null;
        }
        $request->validate(['is_public' => ['required', 'boolean']]);

        return $request->boolean('is_public');
    }

    protected function publishRelated(CampRecord $record): void
    {
        $targets = match ($record->module) {
            'cabins' => [
                'sanitation_rosters' => ['title' => 'Cabin #'.$record->id, 'description' => 'Disinfect and prepare this cabin.', 'status' => 'pending'],
                'room_requirements' => ['room' => 'Cabin #'.$record->id, 'sanitation_requirements' => 'Fragrance-free products and hypoallergenic linens.'],
            ],
            'safety_inspections' => ($record->data['result'] ?? '') === 'failed'
                ? ['emergency_alerts' => ['title' => $record->data['title'], 'description' => $record->data['description']]] : [],
            'activity_commitments' => ['participation' => [
                'title' => $record->data['name'], 'description' => 'Guest activity enrollment',
                'participant' => SecureAccess::newUserQuery()->whereKey($record->owner_id)->value('name'),
                'low_stimulation' => false,
            ]],
            default => [],
        };
        foreach ($targets as $module => $data) {
            if (! CampRecord::where('module', $module)->where('source_id', $record->id)->exists()) {
                $target = new CampRecord;
                $target->forceFill(['module' => $module, 'source_id' => $record->id, 'data' => $data])->save();
                $this->audit($module, 'create', $target->id);
            }
        }
        if ($record->module === 'dietary_needs') {
            SecureAccess::newUserQuery()->whereKey($record->owner_id)->lockForUpdate()->first();
            $projection = CampRecord::where('module', 'dietary_restrictions')->where('owner_type', $record->owner_type)
                ->where('owner_id', $record->owner_id)->lockForUpdate()->first() ?? new CampRecord;
            $guestName = SecureAccess::newUserQuery()->whereKey($record->owner_id)->value('name');
            $projection->forceFill([
                'module' => 'dietary_restrictions', 'owner_type' => $record->owner_type, 'owner_id' => $record->owner_id,
                'data' => [
                    'title' => $guestName ?? 'Guest dietary requirements',
                    'allergens' => $record->data['allergens'],
                    'dietary_instructions' => $record->data['dietary_instructions'],
                ],
            ])->save();
            $this->audit('dietary_restrictions', 'update', $projection->id);
        }
        if ($record->module === 'dietary_needs' || ($record->module === 'meal_plans' && $record->is_public)) {
            $meals = $record->module === 'meal_plans' ? collect([$record])
                : CampRecord::where('module', 'meal_plans')->where('is_public', true)->get();
            $guests = $record->module === 'dietary_needs' ? collect([$record])
                : CampRecord::where('module', 'dietary_restrictions')->get();
            foreach ($guests as $guest) {
                SecureAccess::newUserQuery()->whereKey($guest->owner_id)->lockForUpdate()->first();
                foreach ($meals as $meal) {
                    $exists = CampRecord::where('module', 'special_meals')->where('owner_type', $guest->owner_type)
                        ->where('owner_id', $guest->owner_id)->get()->contains(fn ($special) =>
                            ($special->data['date'] ?? '') === $meal->data['date']
                            && ($special->data['period'] ?? '') === $meal->data['period']);
                    if (! $exists) {
                        $special = new CampRecord;
                        $special->forceFill([
                            'module' => 'special_meals', 'owner_type' => $guest->owner_type, 'owner_id' => $guest->owner_id,
                            'data' => ['name' => 'Pending dietary review', 'date' => $meal->data['date'], 'period' => $meal->data['period'], 'allergens' => '', 'incompatible' => false],
                        ])->save();
                        $this->audit('special_meals', 'create', $special->id);
                    }
                }
            }
        }
        if (in_array($record->module, ['accessibility_work_orders', 'incident_flags'], true)) {
            $destination = $record->data['destination'] ?? 'nursing';
            $maintenance = $record->module === 'accessibility_work_orders' || $destination === 'maintenance';
            $target = new CampRecord;
            $target->forceFill([
                'module' => $maintenance ? 'work_orders' : ($destination === 'behavioral' ? 'behavior_logs' : 'clinical_incidents'),
                'camper_id' => $maintenance ? null : $record->camper_id,
                'data' => $maintenance
                    ? ['title' => 'High priority safety/accessibility request', 'location' => $record->data['location'] ?? 'Staff follow-up required', 'description' => 'Contact reporting staff #'.$record->owner_id.' for a facility-only assessment.', 'status' => 'open']
                    : ['title' => $record->data['title'], 'description' => $record->data['description']],
            ])->save();
            $this->audit($target->module, 'create', $target->id);
        }
    }

    protected function validatedData(Request $request, string $module): array
    {
        $rules = Modules::get($module)['fields'];
        $data = $request->validate($rules);
        foreach ($rules as $field => $rule) {
            if (isset($data[$field]) && in_array('boolean', explode('|', $rule), true)) {
                $data[$field] = in_array($data[$field], [true, 1, '1'], true);
            }
        }
        if (in_array($module, ['registrations', 'bookings'], true) && $data['departure_date'] < $data['arrival_date']) {
            throw ValidationException::withMessages(['departure_date' => 'Departure must be on or after arrival.']);
        }
        return $data;
    }

    protected function audit(string $module, string $action, ?int $id): void
    {
        // Audit every operation, including reads of the sanitized context projections.
        Audit::record($action, $module, $id);
    }

    protected function aggregate(string $module, Request $request): LengthAwarePaginator
    {
        $rows = $this->aggregateRows($module);
        $page = max(1, (int) $request->query('page', 1));

        return new LengthAwarePaginator(array_slice($rows, ($page - 1) * 25, 25), count($rows), 25, $page, ['path' => $request->url()]);
    }

    protected function aggregateRows(string $module): array
    {
        if ($module === 'audit') {
            return CampAudit::query()->latest('id')->limit(250)->get()->map(fn ($audit) => (object) [
                'id' => null, 'data' => $audit->only(['actor_type', 'actor_id', 'action', 'module', 'record_id', 'created_at']),
            ])->all();
        }
        $sources = match ($module) {
            'campers' => ['registrations'],
            'incidents' => ['clinical_incidents', 'incident_flags', 'behavior_logs'],
            'compliance', 'administrative_reports' => ['registrations', 'waivers', 'consents', 'onboarding', 'rosters', 'cabins'],
            'financial_reports' => ['billing', 'payments', 'refunds', 'reimbursements', 'payroll', 'grant_funds', 'grant_applications'],
            default => array_keys(Modules::all()),
        };
        $counts = CampRecord::query()->whereIn('module', $sources)->selectRaw('module, count(*) as total')->groupBy('module')->pluck('total', 'module');

        $rows = array_map(fn ($source) => (object) ['id' => null, 'data' => ['module' => $source, 'records' => (int) ($counts[$source] ?? 0)]], $sources);
        if (in_array($module, ['compliance', 'administrative_reports'], true)) {
            foreach (['waivers', 'consents'] as $document) {
                $missing = CampRecord::where('module', 'registrations')->whereNotIn('id',
                    CampRecord::where('module', $document)->whereNotNull('camper_id')->select('camper_id'))->count();
                $rows[] = (object) ['id' => null, 'data' => ['module' => 'Missing '.$document, 'records' => $missing]];
            }
        }
        if ($module === 'campers') {
            $bands = CampRecord::where('module', 'registrations')->get()->countBy(fn ($record) => $record->data['age_band'] ?? 'unspecified');
            foreach ($bands as $band => $count) {
                $rows[] = (object) ['id' => null, 'data' => ['module' => 'Age band: '.$band, 'records' => $count]];
            }
            $attendance = CampRecord::where('module', 'participation')->get()->countBy(fn ($record) => $record->data['attendance'] ?? 'not recorded');
            foreach ($attendance as $status => $count) {
                $rows[] = (object) ['id' => null, 'data' => ['module' => 'Attendance: '.$status, 'records' => $count]];
            }
        }

        return $rows;
    }

    protected function redirect(string $module)
    {
        return redirect()->route('secure-access.camp.index', ['module' => $module])->with('status', 'Saved.');
    }
}
