<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SecureAccess\Authorization\RepresentativeAccess;
use SecureAccess\Camp\Modules;
use SecureAccess\Models\CampRecord;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamMember;
use SecureAccess\SecureAccess;

class RepresentativeController extends CampController
{
    protected ?TeamAuthorityDocument $authority = null;

    protected ?TeamMember $relationship = null;

    public function workspace(RepresentativeAccess $access)
    {
        $actor = $this->actor();
        $relationships = $access->relationships($actor)->get()->map(function ($relationship) use ($access) {
            $scopes = $access->scopes($relationship);
            if ($scopes === []) {
                return null;
            }

            return $this->withAuthority($access, $relationship, $scopes[0], function () use ($access, $relationship, $scopes) {
                $this->audit('representative_workspace', 'read', null);

                return [
                    'relationship' => $relationship,
                    'adult' => $access->represented($relationship),
                    'scopes' => $scopes,
                ];
            });
        })->filter();

        return response()->view('secure-access::representative.index', compact('relationships'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function module(TeamMember $relationship, string $module, RepresentativeAccess $access)
    {
        $actor = $this->actor();
        $relationship = $access->relationships($actor)->whereKey($relationship->getKey())->firstOrFail();
        $scopes = $access->scopes($relationship);
        $action = in_array($module.'.read', $scopes, true) ? 'read' : 'create';

        return $this->withAuthority($access, $relationship, $module.'.'.$action, function () use ($access, $relationship, $module, $scopes, $action) {
            $definition = Modules::get($module);
            $adult = $access->represented($relationship);
            $records = in_array($module.'.read', $scopes, true)
                ? $access->records($relationship, $module)->latest('id')->paginate(25) : collect();
            foreach ($records as $record) {
                $this->project($record, $definition);
            }
            $this->audit($module, $action === 'read' ? 'read' : 'open', null);

            return response()->view('secure-access::representative.module', compact('relationship', 'module', 'definition', 'adult', 'records', 'scopes'))
                ->header('Cache-Control', 'private, no-store');
        });
    }

    public function storeDelegated(Request $request, TeamMember $relationship, string $module, RepresentativeAccess $access)
    {
        return $this->withAuthority($access, $relationship, $module.'.create', function () use ($request, $relationship, $module, $access) {
            $adult = $access->represented($relationship);
            $record = new CampRecord;
            $record->forceFill([
                'module' => $module, 'owner_type' => $adult->getMorphClass(),
                'owner_id' => (string) $adult->getKey(), 'is_public' => false,
                'data' => $this->validatedData($request, $module),
            ])->save();
            $this->audit($module, 'create', $record->id);
            $this->publishRelated($record);

            return redirect()->route('secure-access.representative.module', [$relationship, $module]);
        });
    }

    public function updateDelegated(Request $request, TeamMember $relationship, string $module, int $record, RepresentativeAccess $access)
    {
        return $this->withAuthority($access, $relationship, $module.'.update', function () use ($request, $relationship, $module, $record, $access) {
            $record = $access->records($relationship, $module)->whereKey($record)->lockForUpdate()->firstOrFail();
            $record->data = $this->validatedData($request, $module)
                + array_intersect_key($record->data, array_flip(['status']));
            $record->save();
            $this->audit($module, 'update', $record->id);
            $this->publishRelated($record);

            return redirect()->route('secure-access.representative.module', [$relationship, $module]);
        });
    }

    public function actionDelegated(Request $request, TeamMember $relationship, string $module, int $record, string $action, RepresentativeAccess $access)
    {
        abort_unless(in_array($action, ['cancel', 'pay', 'submit', 'commit'], true), 404);

        return $this->withAuthority($access, $relationship, $module.'.'.$action, function () use ($relationship, $module, $record, $action, $access) {
            $record = $access->records($relationship, $module)->whereKey($record)->lockForUpdate()->firstOrFail();
            if ($action === 'commit') {
                $adult = $access->represented($relationship);
                $adult = SecureAccess::newUserQuery()->whereKey($adult->getKey())->lockForUpdate()->firstOrFail();
                $commitment = $access->records($relationship, 'activity_commitments')->get()->first(fn ($entry) =>
                    ($entry->data['target_module'] ?? null) === $module
                    && (string) ($entry->data['target_id'] ?? '') === (string) $record->id);
                $commitment ??= new CampRecord;
                $commitment->forceFill([
                    'module' => 'activity_commitments', 'owner_type' => $adult->getMorphClass(),
                    'owner_id' => (string) $adult->getKey(),
                    'data' => ['name' => $record->data['title'], 'target_module' => $module, 'target_id' => $record->id, 'status' => 'enrolled'],
                ])->save();
                $this->audit($module, 'commit', $record->id);
                $this->publishRelated($commitment);
            } else {
                if ($action === 'cancel' && ($record->data['arrival_date'] ?? '') <= now()->toDateString()) {
                    throw ValidationException::withMessages(['arrival_date' => 'Only upcoming reservations can be cancelled.']);
                }
                $record->data = ['status' => ['cancel' => 'cancelled', 'pay' => 'pending_payment', 'submit' => 'submitted'][$action]] + $record->data;
                $record->save();
                $this->audit($module, $action, $record->id);
            }

            return redirect()->route('secure-access.representative.index');
        });
    }

    protected function actor(): Model
    {
        $actor = SecureAccess::guard()->user();
        abort_unless(config('secure-access-acl.enabled') && $actor instanceof Model && $actor->exists
            && in_array($actor->getAttribute('registration_role'), ['family_caregiver', 'professional'], true), 403);

        return $actor;
    }

    protected function withAuthority(RepresentativeAccess $access, TeamMember $relationship, string $capability, \Closure $callback): mixed
    {
        return $access->run($this->actor(), $relationship, $capability, function ($relationship, $document) use ($callback) {
            $this->authority = $document;
            $this->relationship = $relationship;

            return $callback();
        });
    }

    protected function audit(string $module, string $action, ?int $id): void
    {
        $actor = $this->actor();
        abort_unless($this->authority && $this->relationship, 403);
        DB::table('secure_access_representative_audits')->insert([
            'actor_type' => $actor->getMorphClass(), 'actor_id' => (string) $actor->getKey(),
            'represented_id' => $this->relationship->self_advocate_id,
            'document_id' => $this->authority->getKey(), 'module' => $module,
            'action' => $action, 'record_id' => $id, 'created_at' => now(),
        ]);
    }
}
