<?php

namespace SecureAccess\Authorization;

class CampDefaults
{
    /**
     * Default camp group definitions for database initialization.
     * Used by migrations to seed new installations.
     *
     * @return array<string, array{name: string, permissions: array<string>}>
     */
    public static function groups(): array
    {
        $groups = [
            'executive-director' => [
                'name' => 'Executive Director',
                'permissions' => [
                    'system.read_all', 'audit.read_all', 'executive_users.manage_all',
                    'campers.read_aggregate', 'incidents.read_aggregate',
                    'revenue.manage_all', 'budgets.manage_all', 'financial_approvals.manage_all',
                    'grant_applications.create_all', 'grant_applications.read_all', 'grant_applications.update_all',
                    'grant_applications.approve_all', 'public_content.manage_all', 'board_documents.manage_all',
                    'groups.manage_all', 'permissions.manage_all', 'invitations.create_all',
                ],
            ],
            'administrative-director' => [
                'name' => 'Administrative Director',
                'permissions' => [
                    'users.manage_all', 'credentials.manage_all',
                    'rosters.manage_all', 'cabins.manage_all', 'policies.manage_all', 'onboarding.manage_all',
                    'registrations.manage_all', 'waivers.manage_all', 'consents.manage_all', 'households.manage_all',
                    'compliance.read_all', 'administrative_reports.export_all', 'form_templates.manage_all',
                    'groups.manage_all', 'invitations.create_all',
                ],
            ],
            'medical-nursing' => [
                'name' => 'Medical & Nursing Staff',
                'permissions' => [
                    'medications.manage_all', 'medication_stock.manage_all', 'dosages.manage_all',
                    'health_profiles.manage_all', 'clinical_protocols.manage_all', 'clinical_incidents.manage_all',
                ],
            ],
            'behavioral-support' => [
                'name' => 'Behavioral Support Services',
                'permissions' => [
                    'support_plans.manage_all', 'behavior_logs.manage_all',
                    'behavior_health_context.read_only', 'consultation_notes.manage_all',
                ],
            ],
            'accessibility-adaptive' => [
                'name' => 'Accessibility & Adaptive Services',
                'permissions' => [
                    'accommodations.read_all', 'accommodations.update_all', 'adaptive_equipment.manage_all',
                    'facility_audits.manage_all', 'accessibility_work_orders.create_all', 'accessibility_work_orders.read_all',
                ],
            ],
            'program-directors' => [
                'name' => 'Program Directors',
                'permissions' => [
                    'activity_schedules.manage_all', 'activity_adaptations.manage_all',
                    'participation.read_all', 'participation.update_all', 'safety_alerts.read_only',
                ],
            ],
            'counselors-dsps' => [
                'name' => 'Counselors & DSPs',
                'permissions' => [
                    'care_plans.read_assigned', 'behavior_strategies.read_assigned', 'allergy_alerts.read_assigned',
                    'daily_logs.create_assigned', 'daily_logs.read_assigned', 'incident_flags.create_assigned',
                ],
            ],
            'facilities-maintenance' => [
                'name' => 'Facilities & Cabin Maintenance',
                'permissions' => [
                    'work_orders.read_all', 'work_orders.update_all', 'work_orders.assign_all',
                    'safety_inspections.create_all', 'safety_inspections.read_all', 'safety_inspections.update_all',
                    'emergency_alerts.read_all', 'emergency_alerts.acknowledge_all',
                ],
            ],
            'housekeeping' => [
                'name' => 'Housekeeping',
                'permissions' => [
                    'sanitation_rosters.read_all', 'sanitation_rosters.update_all',
                    'room_requirements.read_all', 'room_requirements.update_all',
                    'supply_requests.create_own', 'supply_requests.read_own',
                ],
            ],
            'catering-dietary' => [
                'name' => 'Catering & Dietary Support',
                'permissions' => [
                    'dietary_restrictions.read_only', 'menus.manage_all', 'ingredients.manage_all',
                    'allergen_notices.manage_all', 'special_meals.read_all', 'special_meals.update_all',
                ],
            ],
            'finance-accounting' => [
                'name' => 'Finance & Accounting',
                'permissions' => [
                    'billing.manage_all', 'payments.manage_all', 'refunds.manage_all', 'reimbursements.manage_all',
                    'payroll.manage_all', 'time_cards.approve_all', 'grant_funds.manage_all',
                    'grant_applications.read_all', 'grant_applications.update_all',
                    'financial_reports.export_all',
                ],
            ],
            'guest' => [
                'name' => 'Guest',
                'permissions' => [
                    'bookings.create_own', 'bookings.read_own', 'bookings.update_own', 'bookings.cancel_own',
                    'booking_balances.pay_own',
                    'accommodations.create_own', 'accommodations.read_own', 'accommodations.update_own',
                    'health_disclosures.create_own', 'health_disclosures.read_own', 'health_disclosures.update_own',
                    'dietary_needs.create_own', 'dietary_needs.read_own', 'dietary_needs.update_own',
                    'meal_plans.read_public', 'special_meals.read_own', 'activities.read_public', 'events.read_public',
                    'activity_commitments.create_own', 'activity_commitments.read_own', 'activity_commitments.update_own',
                    'grant_applications.create_own', 'grant_applications.read_own',
                    'grant_applications.update_own', 'grant_applications.submit_own',
                ],
            ],
        ];

        foreach (\SecureAccess\Camp\Modules::grants() as $role => $permissions) {
            $groups[$role]['permissions'] = array_values(array_unique([
                ...$groups[$role]['permissions'], ...$permissions,
            ]));
        }

        return $groups;
    }
}
