<?php

namespace SecureAccess\Authorization;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use SecureAccess\Models\CampRecord;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamMember;
use SecureAccess\SecureAccess;

class RepresentativeAccess
{
    public static function capabilities(): array
    {
        return [
            'administrative' => [
                'bookings.create', 'bookings.read', 'bookings.update', 'bookings.cancel',
                'accommodations.create', 'accommodations.read', 'accommodations.update',
                'activities.read', 'activities.commit', 'events.read', 'events.commit',
                'activity_commitments.read', 'meal_plans.read', 'special_meals.read',
            ],
            'healthcare' => [
                'health_disclosures.create', 'health_disclosures.read', 'health_disclosures.update',
                'dietary_needs.create', 'dietary_needs.read', 'dietary_needs.update',
            ],
            'financial' => [
                'booking_balances.read', 'booking_balances.pay',
                'grant_applications.create', 'grant_applications.read',
                'grant_applications.update', 'grant_applications.submit',
            ],
            'messaging_contact' => ['households.create', 'households.read', 'households.update'],
        ];
    }

    public function relationships(Model $actor): Builder
    {
        return TeamMember::query()->where('team_member_id', (string) $actor->getKey())
            ->where('team_member_role', $actor->getAttribute('registration_role'))
            ->where('self_advocate_id', '!=', (string) $actor->getKey())
            ->whereNull('ended_at');
    }

    public function grants(TeamMember $relationship): Builder
    {
        return TeamAuthorityDocument::query()->where('team_member_id', $relationship->getKey())
            ->where('review_decision', 'confirmed')
            ->where('lifecycle_status', 'active')
            ->where('status', 'approved')
            ->whereHas('relationship', fn ($query) => $query->whereNull('ended_at'))
            ->whereNotNull('effective_at')->where('effective_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopes(TeamMember $relationship): array
    {
        return array_values(array_intersect(
            array_merge(...array_values(self::capabilities())),
            $this->grants($relationship)->get()->flatMap(fn ($document) => $document->approved_scopes ?? [])->all()
        ));
    }

    public function represented(TeamMember $relationship): Model
    {
        return SecureAccess::newUserQuery()->findOrFail($relationship->self_advocate_id);
    }

    public function records(TeamMember $relationship, string $module): Builder
    {
        $adult = $this->represented($relationship);
        $query = CampRecord::query()->where('module', $module);

        return in_array($module, ['activities', 'events', 'meal_plans'], true)
            ? $query->where('is_public', true)
            : $query->where('owner_type', $adult->getMorphClass())->where('owner_id', (string) $adult->getKey());
    }

    public function run(Model $actor, TeamMember $relationship, string $capability, Closure $callback): mixed
    {
        abort_unless(in_array($capability, array_merge(...array_values(self::capabilities())), true), 403);

        return DB::transaction(function () use ($actor, $relationship, $capability, $callback) {
            if (in_array($capability, ['grant_applications.create', 'grant_applications.update', 'grant_applications.submit'], true)) {
                $relationship = $this->relationships($actor)->whereKey($relationship->getKey())->firstOrFail();
                SecureAccess::newUserQuery()->whereKey($relationship->self_advocate_id)->lockForUpdate()->firstOrFail();
                abort_unless(app(\SecureAccess\Services\FinancialApplicationPermissionService::class)
                    ->allowsApplications($relationship->self_advocate_id), 403);
            }
            $relationship = $this->relationships($actor)->whereKey($relationship->getKey())->lockForUpdate()->firstOrFail();
            $document = $this->grants($relationship)->lockForUpdate()->get()
                ->first(fn ($document) => in_array($capability, $document->approved_scopes ?? [], true));
            abort_unless($document, 403);

            return $callback($relationship, $document);
        });
    }
}
