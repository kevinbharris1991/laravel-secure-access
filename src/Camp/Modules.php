<?php

namespace SecureAccess\Camp;

use Illuminate\Support\Facades\Gate;
use SecureAccess\Authorization\AccessScope;
use SecureAccess\Models\CampRecord;
use SecureAccess\Models\Permission;

class Modules
{
    public static function all(): array
    {
        $modules = [];
        $add = function (string $role, array $names, array $actions, string $scope = 'all', bool $sensitive = false) use (&$modules): void {
            foreach ($names as $name) {
                $modules[$name] ??= [
                    'title' => ucwords(str_replace('_', ' ', $name)),
                    'fields' => self::fields($name),
                    'actions' => [], 'sensitive' => false, 'permissions' => [], 'scopes' => [],
                    'aggregate' => in_array($name, ['system', 'audit', 'campers', 'incidents', 'compliance', 'administrative_reports', 'financial_reports'], true),
                ];
                $modules[$name]['permissions'][$role] = $actions;
                $modules[$name]['scopes'][$role] = $scope;
                $modules[$name]['actions'] = array_values(array_unique(array_merge($modules[$name]['actions'], $actions)));
                $modules[$name]['sensitive'] = $modules[$name]['sensitive'] || $sensitive;
            }
        };
        $crud = ['create', 'read', 'update', 'delete'];
        $add('executive-director', ['system', 'audit'], ['read'], 'all', true);
        $add('executive-director', ['campers', 'incidents'], ['read'], 'aggregate', true);
        $add('executive-director', ['revenue', 'budgets', 'public_content', 'board_documents'], $crud);
        $add('executive-director', ['financial_approvals'], [...$crud, 'approve'], 'all', true);
        $add('executive-director', ['grant_applications'], ['create', 'read', 'update', 'approve'], 'all', true);
        $add('administrative-director', ['rosters', 'cabins', 'policies', 'onboarding', 'registrations', 'waivers', 'consents', 'households', 'form_templates'], $crud, 'all', true);
        $add('administrative-director', ['compliance'], ['read'], 'all', true);
        $add('administrative-director', ['administrative_reports'], ['export'], 'all', true);
        $add('medical-nursing', ['medications', 'medication_stock', 'dosages', 'health_profiles', 'clinical_protocols', 'clinical_incidents'], $crud, 'all', true);
        $add('behavioral-support', ['support_plans', 'behavior_logs', 'consultation_notes'], $crud, 'all', true);
        $add('behavioral-support', ['behavior_health_context'], ['read'], 'only', true);
        $add('accessibility-adaptive', ['accommodations'], ['read', 'update'], 'all', true);
        $add('accessibility-adaptive', ['adaptive_equipment', 'facility_audits'], $crud);
        $add('accessibility-adaptive', ['accessibility_work_orders'], ['create', 'read']);
        $add('program-directors', ['activity_schedules', 'activity_adaptations'], $crud);
        $add('program-directors', ['participation'], ['read', 'update']);
        $add('program-directors', ['safety_alerts'], ['read'], 'only', true);
        $add('counselors-dsps', ['care_plans', 'behavior_strategies', 'allergy_alerts'], ['read'], 'assigned', true);
        $add('counselors-dsps', ['daily_logs'], ['create', 'read'], 'assigned', true);
        $add('counselors-dsps', ['incident_flags'], ['create'], 'assigned', true);
        $add('facilities-maintenance', ['work_orders'], ['read', 'update', 'assign']);
        $add('facilities-maintenance', ['safety_inspections'], ['create', 'read', 'update']);
        $add('facilities-maintenance', ['emergency_alerts'], ['read', 'acknowledge']);
        $add('housekeeping', ['sanitation_rosters', 'room_requirements'], ['read', 'update']);
        $add('housekeeping', ['supply_requests'], ['create', 'read'], 'own');
        $add('catering-dietary', ['dietary_restrictions'], ['read'], 'only', true);
        $add('catering-dietary', ['menus', 'ingredients', 'allergen_notices'], $crud);
        $add('catering-dietary', ['special_meals'], ['read', 'update'], 'all', true);
        $add('finance-accounting', ['billing', 'payments', 'refunds', 'reimbursements', 'payroll', 'grant_funds'], $crud, 'all', true);
        $add('finance-accounting', ['time_cards'], ['read', 'approve'], 'all', true);
        $add('finance-accounting', ['grant_applications'], ['read', 'update'], 'all', true);
        $add('finance-accounting', ['financial_reports'], ['export'], 'all', true);
        $add('guest', ['bookings'], ['create', 'read', 'update', 'cancel'], 'own', true);
        $add('guest', ['booking_balances'], ['pay'], 'own', true);
        $add('guest', ['accommodations', 'health_disclosures', 'dietary_needs', 'activity_commitments'], ['create', 'read', 'update'], 'own', true);
        $add('guest', ['meal_plans', 'activities', 'events'], ['read'], 'public');
        $add('guest', ['activities', 'events'], ['read', 'commit'], 'public');
        $add('guest', ['special_meals'], ['read'], 'own', true);
        $add('guest', ['grant_applications'], ['create', 'read', 'update', 'submit'], 'own', true);

        return $modules;
    }

    public static function get(string $module): array
    {
        $definition = self::all()[$module] ?? null;
        abort_unless($definition !== null, 404);

        return $definition;
    }

    public static function grants(): array
    {
        $grants = [];
        foreach (self::all() as $module => $definition) {
            foreach ($definition['permissions'] as $role => $actions) {
                foreach ($actions as $action) {
                    $grants[$role][] = $module.'.'.$action.'_'.$definition['scopes'][$role];
                }
            }
        }

        return $grants;
    }

    public static function subject($user, string $module, ?CampRecord $record = null): AccessScope
    {
        self::get($module);

        return $record ?? new CollectionScope($user);
    }

    public static function permission($user, string $module, string $action, ?CampRecord $record = null): ?string
    {
        $definition = self::get($module);
        if (! $user || ($record && $record->module !== $module)) {
            return null;
        }
        $candidates = [];
        foreach ($definition['permissions'] as $role => $actions) {
            if (in_array($action, $actions, true)) {
                $candidates[] = $module.'.'.$action.'_'.$definition['scopes'][$role];
            }
        }
        // Prefer broader grants so the collection filter matches the effective grant.
        usort($candidates, fn ($a, $b) => self::rank($a) <=> self::rank($b));
        foreach (array_unique($candidates) as $permission) {
            $scope = str_ends_with($permission, '_own') ? 'own' : (str_ends_with($permission, '_assigned') ? 'assigned' : 'all');
            // A renamed or re-scoped grant must never widen a collection query.
            if (! Permission::query()->where('key', $permission)->where('scope', $scope)->exists()) {
                continue;
            }
            if ($record && str_ends_with($permission, '_public') && ! $record->is_public) {
                continue;
            }
            if ($record && str_ends_with($permission, '_own') && ! $record->isOwnedBy($user)) {
                continue;
            }
            if ($record && str_ends_with($permission, '_assigned') && ! $record->isAssignedTo($user)) {
                continue;
            }
            $subject = $scope === 'all' ? null : self::subject($user, $module, $record);
            if (Gate::forUser($user)->allows('secure-access', [$permission, $subject])) {
                return $permission;
            }
        }

        return null;
    }

    public static function visible($user): array
    {
        $visible = [];
        foreach (self::all() as $module => $definition) {
            foreach (['read', 'create', 'export', ...$definition['actions']] as $action) {
                if ($permission = self::permission($user, $module, $action)) {
                    $visible[$module] = ['title' => $definition['title'], 'permission' => $permission, 'subject' => self::subject($user, $module)];
                    break;
                }
            }
        }

        return $visible;
    }

    private static function rank(string $permission): int
    {
        return str_ends_with($permission, '_own') ? 2 : (str_ends_with($permission, '_assigned') ? 1 : 0);
    }

    private static function fields(string $module): array
    {
        $text = 'required|string|max:5000';
        $name = 'required|string|max:200';
        $amount = 'required|numeric|min:0|max:99999999.99';
        $date = 'required|date_format:Y-m-d';
        $optional = 'nullable|string|max:5000';

        return match ($module) {
            'system', 'audit', 'campers', 'incidents', 'compliance', 'administrative_reports', 'financial_reports' => [],
            'registrations' => ['name' => $name, 'arrival_date' => $date, 'departure_date' => $date, 'contact' => $name],
            'bookings' => ['title' => $name, 'arrival_date' => $date, 'departure_date' => $date],
            'medications', 'medication_stock' => ['name' => $name, 'quantity' => 'required|integer|min:0|max:100000', 'instructions' => $text],
            'dosages' => ['medication' => $name, 'dose' => $name, 'scheduled_at' => 'required|date', 'instructions' => $text],
            'health_profiles', 'health_disclosures' => ['title' => $name, 'medical_history' => $text, 'medications' => $optional, 'allergies' => $optional],
            'dietary_needs', 'dietary_restrictions' => ['title' => $name, 'allergens' => $text, 'dietary_instructions' => $text],
            'safety_alerts', 'allergy_alerts' => ['title' => $name, 'alert' => $text, 'response' => $text],
            'behavior_health_context' => ['title' => $name, 'support_context' => $text],
            'care_plans', 'behavior_strategies', 'support_plans' => ['title' => $name, 'instructions' => $text],
            'accommodations' => ['title' => $name, 'accessibility_requirements' => $text],
            'room_requirements' => ['room' => $name, 'sanitation_requirements' => $text],
            'work_orders', 'accessibility_work_orders' => ['title' => $name, 'location' => $name, 'description' => $text, 'status' => 'required|in:open,assigned,in_progress,completed'],
            'activities', 'events', 'activity_schedules' => ['title' => $name, 'scheduled_at' => 'required|date', 'location' => $name, 'description' => $text],
            'menus' => ['title' => $name, 'service_date' => $date, 'ingredients' => $text, 'allergens' => $text],
            'meal_plans' => ['name' => $name, 'date' => $date, 'period' => 'required|in:breakfast,lunch,dinner', 'allergens' => $optional],
            'special_meals' => ['name' => $name, 'date' => $date, 'period' => 'required|in:breakfast,lunch,dinner', 'allergens' => $optional, 'incompatible' => 'required|boolean'],
            'billing', 'payments', 'refunds', 'reimbursements', 'payroll', 'grant_funds', 'budgets', 'revenue', 'financial_approvals' => ['title' => $name, 'amount' => $amount, 'description' => $text],
            'grant_applications' => ['title' => $name, 'amount' => $amount, 'purpose' => $text],
            'booking_balances' => ['title' => $name, 'amount' => $amount],
            'activity_commitments' => ['title' => $name, 'activity_id' => 'required|integer|min:1', 'notes' => $optional],
            'time_cards' => ['title' => $name, 'hours' => 'required|numeric|min:0|max:168', 'week_start' => $date],
            default => ['title' => $name, 'description' => $text],
        };
    }
}
