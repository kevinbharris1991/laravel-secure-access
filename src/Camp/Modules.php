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
        $cru = ['create', 'read', 'update'];
        $add('executive-director', ['system', 'audit'], ['read'], 'all', true);
        $add('executive-director', ['audit'], ['read', 'audit'], 'all', true);
        $add('executive-director', ['campers', 'incidents'], ['read'], 'aggregate', true);
        $add('executive-director', ['revenue', 'budgets'], $cru, 'all', true);
        $add('executive-director', ['public_content', 'board_documents'], $crud);
        $add('executive-director', ['financial_approvals'], [...$cru, 'approve'], 'all', true);
        $add('executive-director', ['grant_applications'], ['create', 'read', 'update', 'approve'], 'all', true);
        $add('administrative-director', ['rosters', 'cabins', 'policies', 'onboarding', 'registrations', 'waivers', 'consents', 'households'], $cru, 'all', true);
        $add('administrative-director', ['rosters'], [...$cru, 'assign'], 'all', true);
        $add('administrative-director', ['form_templates'], $crud, 'all', true);
        $add('administrative-director', ['compliance'], ['read', 'audit'], 'all', true);
        $add('administrative-director', ['administrative_reports'], ['export'], 'all', true);
        $add('medical-nursing', ['medications', 'medication_stock', 'dosages'], $crud, 'all', true);
        $add('medical-nursing', ['health_profiles', 'clinical_protocols', 'clinical_incidents', 'care_plans', 'allergy_alerts', 'safety_alerts', 'behavior_health_context'], $cru, 'all', true);
        $add('medical-nursing', ['health_disclosures', 'dietary_needs'], ['read'], 'all', true);
        $add('behavioral-support', ['support_plans', 'consultation_notes', 'behavior_strategies'], $crud, 'all', true);
        $add('behavioral-support', ['behavior_logs'], $cru, 'all', true);
        $add('behavioral-support', ['behavior_health_context'], ['read'], 'only', true);
        $add('accessibility-adaptive', ['accommodations'], ['read', 'update'], 'all', true);
        $add('accessibility-adaptive', ['adaptive_equipment'], $crud);
        $add('accessibility-adaptive', ['facility_audits'], $cru);
        $add('accessibility-adaptive', ['accessibility_work_orders'], ['create', 'read']);
        $add('program-directors', ['activity_schedules', 'activities', 'events'], $crud);
        $add('program-directors', ['activity_adaptations'], $cru);
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
        $add('catering-dietary', ['menus', 'ingredients', 'allergen_notices', 'meal_plans'], $cru);
        $add('catering-dietary', ['special_meals'], ['read', 'update'], 'all', true);
        $add('finance-accounting', ['billing', 'payments', 'refunds', 'reimbursements', 'payroll', 'grant_funds'], $crud, 'all', true);
        $add('finance-accounting', ['time_cards'], ['read', 'approve'], 'all', true);
        $add('finance-accounting', ['grant_applications'], ['read', 'update'], 'all', true);
        $add('finance-accounting', ['financial_reports'], ['export'], 'all', true);
        $add('guest', ['bookings'], ['create', 'read', 'update', 'cancel'], 'own', true);
        $add('finance-accounting', ['booking_balances'], $cru, 'all', true);
        $add('guest', ['booking_balances'], ['read', 'pay'], 'own', true);
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
            if ($module === 'activity_commitments' && in_array($action, ['create', 'update'], true)) {
                continue;
            }
            $storedScope = Permission::query()->where('key', $permission)->value('scope');
            if (! in_array($storedScope, ['all', 'own', 'assigned'], true)
                || ($definition['aggregate'] && $storedScope !== 'all')) {
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
            $subject = self::subject($user, $module, $record);
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
            'registrations' => ['name' => $name, 'arrival_date' => $date, 'departure_date' => $date, 'contact' => $name, 'age_band' => 'nullable|in:under_18,18_34,35_54,55_plus'],
            'bookings' => ['title' => $name, 'arrival_date' => $date, 'departure_date' => $date],
            'medications', 'medication_stock' => ['name' => $name, 'quantity' => 'required|integer|min:0|max:100000', 'instructions' => $text],
            'dosages' => ['medication' => $name, 'dose' => $name, 'scheduled_at' => 'required|date', 'instructions' => $text, 'administered_at' => 'nullable|date|required_if:status,administered', 'status' => 'nullable|in:scheduled,administered,withheld,missed'],
            'health_profiles', 'health_disclosures' => ['title' => $name, 'medical_history' => $text, 'medications' => $optional, 'allergies' => $optional, 'dosage' => $optional, 'doctor_contact' => $optional, 'administration_instructions' => $optional],
            'dietary_needs' => ['title' => $name, 'allergens' => $text, 'dietary_instructions' => $text, 'environmental_allergies' => $optional, 'drug_allergies' => $optional, 'reaction_severity' => $optional],
            'dietary_restrictions' => ['title' => $name, 'allergens' => $text, 'dietary_instructions' => $text],
            'safety_alerts', 'allergy_alerts' => ['title' => $name, 'alert' => $text, 'response' => $text],
            'behavior_health_context' => ['title' => $name, 'support_context' => $text],
            'care_plans', 'behavior_strategies', 'support_plans' => ['title' => $name, 'instructions' => $text, 'communication_methods' => $optional, 'sensory_triggers' => $optional, 'calming_protocols' => $optional],
            'behavior_logs' => ['title' => $name, 'description' => $text, 'antecedent' => $optional, 'behavior' => $optional, 'consequence' => $optional],
            'accommodations' => ['title' => $name, 'accessibility_requirements' => $text, 'equipment' => $optional, 'cabin' => $optional, 'mobility_requirements' => $optional, 'sensory_requirements' => $optional],
            'adaptive_equipment' => ['title' => $name, 'description' => $text, 'checkout_status' => 'nullable|in:available,checked_out,maintenance'],
            'participation' => ['title' => $name, 'description' => $text, 'participant' => $optional, 'attendance' => 'nullable|in:present,absent', 'low_stimulation' => 'nullable|boolean'],
            'incident_flags' => ['title' => $name, 'description' => $text, 'destination' => 'nullable|in:nursing,behavioral,maintenance', 'location' => $optional],
            'room_requirements' => ['room' => $name, 'sanitation_requirements' => $text, 'lighting' => $optional, 'hypoallergenic_linens' => 'nullable|boolean'],
            'sanitation_rosters' => ['title' => $name, 'description' => $text, 'status' => 'nullable|in:pending,disinfected,ready'],
            'safety_inspections' => ['title' => $name, 'description' => $text, 'result' => 'nullable|in:passed,failed'],
            'work_orders', 'accessibility_work_orders' => ['title' => $name, 'location' => $name, 'description' => $text, 'status' => 'required|in:open,assigned,in_progress,completed'],
            'activities', 'events', 'activity_schedules' => ['title' => $name, 'scheduled_at' => 'required|date', 'location' => $name, 'description' => $text],
            'menus' => ['name' => $name, 'date' => $date, 'period' => 'required|in:breakfast,lunch,dinner', 'ingredients' => $text, 'allergens' => $optional],
            'meal_plans' => ['name' => $name, 'date' => $date, 'period' => 'required|in:breakfast,lunch,dinner', 'allergens' => $optional],
            'special_meals' => ['name' => $name, 'date' => $date, 'period' => 'required|in:breakfast,lunch,dinner', 'allergens' => $optional, 'incompatible' => 'required|boolean'],
            'billing', 'payments', 'refunds', 'reimbursements', 'payroll', 'grant_funds', 'budgets', 'revenue', 'financial_approvals' => ['title' => $name, 'amount' => $amount, 'description' => $text],
            'grant_applications' => ['title' => $name, 'amount' => $amount, 'purpose' => $text],
            'booking_balances' => ['title' => $name, 'amount' => $amount],
            'activity_commitments' => ['name' => $name, 'target_module' => 'required|in:activities,events', 'target_id' => 'required|integer|min:1', 'status' => 'required|in:enrolled,cancelled'],
            'time_cards' => ['title' => $name, 'hours' => 'required|numeric|min:0|max:168', 'week_start' => $date],
            default => ['title' => $name, 'description' => $text],
        };
    }
}
