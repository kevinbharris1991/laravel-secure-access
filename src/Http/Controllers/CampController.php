<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SecureAccess\Camp\Modules;
use SecureAccess\Models\CampAssignment;
use SecureAccess\Models\CampAudit;
use SecureAccess\Models\CampRecord;
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
            abort_unless(isset($permissions['read']) || isset($permissions['export']), 403);
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
        $definition = Modules::get($module);
        abort_if($definition['aggregate'], 403);
        $data = $this->validatedData($request, $module);
        $camperId = $this->camperId($request, $permission);
        DB::transaction(function () use ($module, $data, $camperId) {
            $user = SecureAccess::guard()->user();
            $record = new CampRecord;
            $record->module = $module;
            $record->owner_type = $user->getMorphClass();
            $record->owner_id = (string) $user->getKey();
            $record->camper_id = $camperId;
            $record->data = $data;
            $record->save();
            if ($module === 'registrations') {
                $record->camper_id = $record->id;
                $record->save();
            }
            $this->audit($module, 'create', $record->id);
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
        $data = $this->validatedData($request, $module);
        DB::transaction(function () use ($record, $data, $module) {
            // Ownership and assignment identifiers are deliberately never request-writable.
            $record->data = $data + array_intersect_key($record->data, array_flip(['status', 'assignee_id', 'assignee_type', 'acknowledged_by']));
            $record->save();
            $this->audit($module, 'update', $record->id);
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
        abort_unless(in_array($action, ['assign', 'acknowledge', 'approve', 'submit', 'cancel', 'commit'], true), 404);
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
        if ($action === 'commit') {
            $this->authorizeAction('activity_commitments', 'create');
        }
        DB::transaction(function () use ($record, $module, $action, $assignee) {
            $user = SecureAccess::guard()->user();
            if ($action === 'commit') {
                $commitment = new CampRecord;
                $commitment->module = 'activity_commitments';
                $commitment->owner_type = $user->getMorphClass();
                $commitment->owner_id = (string) $user->getKey();
                $commitment->data = ['title' => $record->data['title'], 'activity_id' => $record->id, 'source_module' => $module];
                $commitment->save();
                $this->audit('activity_commitments', 'create', $commitment->id);
            } else {
                $data = $record->data;
                if ($action === 'assign') {
                    $data['assignee_id'] = (string) $assignee->getKey();
                    $data['assignee_type'] = $assignee->getMorphClass();
                    $data['status'] = 'assigned';
                } elseif ($action === 'acknowledge') {
                    $data['acknowledged_by'] = array_values(array_unique([...(array) ($data['acknowledged_by'] ?? []), $user->getMorphClass().':'.$user->getKey()]));
                } else {
                    $data['status'] = ['approve' => 'approved', 'submit' => 'submitted', 'cancel' => 'cancelled'][$action];
                }
                $record->data = $data;
                $record->save();
            }
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
        $definition = Modules::get($module);
        $user = SecureAccess::guard()->user();
        $permissions = $this->permissions($user, $module, $record);
        $subject = Modules::subject($user, $module, $record);
        $this->audit($module, 'read', $record->id);
        $this->project($record, $definition);
        $messages = session('errors')?->all() ?? [];

        return view('secure-access::camp.'.$view, compact('module', 'definition', 'record', 'permissions', 'subject', 'messages'));
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
            if ($permission = Modules::permission($user, $module, $action, $record)) {
                $permissions[$action] = $permission;
            }
        }

        return $permissions;
    }

    protected function query(string $module, string $permission, $user): Builder
    {
        $query = CampRecord::query()->where('module', $module);
        if (str_ends_with($permission, '_own')) {
            $query->where('owner_type', $user->getMorphClass())->where('owner_id', (string) $user->getKey());
        } elseif (str_ends_with($permission, '_assigned')) {
            $query->whereIn('camper_id', CampAssignment::query()->select('camper_id')
                ->where('user_type', $user->getMorphClass())->where('user_id', (string) $user->getKey()));
        } elseif (str_ends_with($permission, '_public')) {
            $query->where('is_public', true);
        }

        return $query;
    }

    protected function camperId(Request $request, string $permission): ?int
    {
        $assigned = str_ends_with($permission, '_assigned');
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

    protected function validatedData(Request $request, string $module): array
    {
        $data = $request->validate(Modules::get($module)['fields']);
        if (in_array($module, ['registrations', 'bookings'], true) && $data['departure_date'] < $data['arrival_date']) {
            throw ValidationException::withMessages(['departure_date' => 'Departure must be on or after arrival.']);
        }
        if ($module === 'activity_commitments') {
            $activity = CampRecord::query()->whereIn('module', ['activities', 'events'])->find($data['activity_id']);
            abort_unless($activity, 422, 'Choose an existing published activity or event.');
            $this->authorizeAction($activity->module, 'read', $activity);
        }

        return $data;
    }

    protected function audit(string $module, string $action, ?int $id): void
    {
        // Audit every operation, including reads of the sanitized context projections.
        $user = SecureAccess::guard()->user();
        $audit = new CampAudit;
        $audit->actor_type = $user->getMorphClass();
        $audit->actor_id = (string) $user->getKey();
        $audit->action = $action;
        $audit->module = $module;
        $audit->record_id = $id;
        $audit->save();
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

        return array_map(fn ($source) => (object) ['id' => null, 'data' => ['module' => $source, 'records' => (int) ($counts[$source] ?? 0)]], $sources);
    }

    protected function redirect(string $module)
    {
        return redirect()->route('secure-access.camp.index', ['module' => $module])->with('status', 'Saved.');
    }
}
