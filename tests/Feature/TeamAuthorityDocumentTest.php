<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamMember;
use SecureAccess\SecureAccess;
use SecureAccess\Tests\TestCase;
use SecureAccess\Tests\Fixtures\User;

class TeamAuthorityDocumentTest extends TestCase
{
    protected string $originalStoragePath;
    protected string $testStoragePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStoragePath = storage_path();
        $this->testStoragePath = sys_get_temp_dir().'/authority-original-tests-'.bin2hex(random_bytes(8));
        $this->app->useStoragePath($this->testStoragePath);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStoragePath);
        \Illuminate\Support\Facades\File::deleteDirectory($this->testStoragePath);
        parent::tearDown();
    }

    protected function pdf(string $name = 'legal-order.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
    }

    protected function teamUser(string $username, string $role): User
    {
        $user = $this->createUser([
            'username' => $username,
            'email' => $username.'@example.com',
            'registration_role' => $role,
        ]);
        $this->actingAs($user, config('secure-access.guard'))->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->getKey(),
        ]);

        return $user;
    }

    protected function relationship(User $professional, User $client): TeamMember
    {
        return TeamMember::query()->create([
            'self_advocate_id' => (string) $client->getKey(),
            'team_member_id' => (string) $professional->getKey(),
            'team_member_role' => $professional->registration_role,
        ]);
    }

    public function test_professional_and_caregiver_can_upload_each_kind_of_authority_document(): void
    {
        Storage::fake('local');

        foreach (['professional', 'family_caregiver'] as $index => $role) {
            $user = $this->teamUser('representative-'.$index, $role);
            $client = $this->createUser([
                'username' => 'client-'.$index,
                'email' => 'client-'.$index.'@example.com',
                'registration_role' => 'self_advocate',
            ]);
            $relationship = $this->relationship($user, $client);

            foreach (TeamAuthorityDocument::TYPES as $type) {
                $this->post(route('secure-access.team.authority-documents.store', $relationship), [
                    'type' => $type,
                    'document' => $this->pdf(),
                ])->assertRedirect(route('secure-access.team.index'));
            }

            $this->assertSame(3, TeamAuthorityDocument::query()->where('team_member_id', $relationship->getKey())->count());
            $this->assertCount(($index + 1) * 3, app(\SecureAccess\Services\AuthorityOriginalStorage::class)->disk()->allFiles());
        }
    }

    public function test_related_client_can_download_document_but_unrelated_users_cannot(): void
    {
        Storage::fake('local');
        $professional = $this->teamUser('professional', 'professional');
        $client = $this->createUser([
            'username' => 'client',
            'email' => 'client@example.com',
            'registration_role' => 'self_advocate',
        ]);
        $relationship = $this->relationship($professional, $client);
        $document = app(\SecureAccess\Services\AuthorityReviewService::class)
            ->submit($professional, $relationship, ['type' => 'guardianship'], $this->pdf());
        $url = route('secure-access.team.authority-documents.download', [$relationship, $document]);

        $this->actingAs($client, config('secure-access.guard'))->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $client->getKey(),
        ]);
        $this->get($url)->assertOk()->assertDownload($document->file_name);

        $unrelatedClient = $this->createUser([
            'username' => 'unrelated',
            'email' => 'unrelated@example.com',
            'registration_role' => 'self_advocate',
        ]);
        $this->actingAs($unrelatedClient, config('secure-access.guard'))->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $unrelatedClient->getKey(),
        ]);
        $this->get($url)->assertNotFound();
    }

    public function test_only_linked_professionals_or_caregivers_can_upload_and_invalid_documents_are_rejected(): void
    {
        Storage::fake('local');
        $professional = $this->teamUser('professional', 'professional');
        $client = $this->createUser([
            'username' => 'client',
            'email' => 'client@example.com',
            'registration_role' => 'self_advocate',
        ]);
        $relationship = $this->relationship($professional, $client);

        $this->actingAs($client, config('secure-access.guard'))->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $client->getKey(),
        ]);
        $this->post(route('secure-access.team.authority-documents.store', $relationship), [
            'type' => 'guardianship',
            'document' => UploadedFile::fake()->create('order.pdf', 100, 'application/pdf'),
        ])->assertNotFound();

        $this->actingAs($professional, config('secure-access.guard'))->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $professional->getKey(),
        ]);
        $this->post(route('secure-access.team.authority-documents.store', $relationship), [
            'type' => 'invalid',
            'document' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors(['type', 'document']);

        $this->assertSame(0, TeamAuthorityDocument::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_removing_team_relationship_preserves_evidence_and_suspends_pending_documents(): void
    {
        Storage::fake('local');
        $professional = $this->teamUser('professional', 'professional');
        $client = $this->createUser([
            'username' => 'client',
            'email' => 'client@example.com',
            'registration_role' => 'self_advocate',
        ]);
        $relationship = $this->relationship($professional, $client);
        $path = UploadedFile::fake()->create('poa.pdf', 100, 'application/pdf')
            ->store('secure-access/authority-documents', 'local');
        TeamAuthorityDocument::query()->create([
            'team_member_id' => $relationship->getKey(),
            'type' => 'power_of_attorney',
            'path' => $path,
            'file_name' => 'poa.pdf',
        ]);

        $this->delete(route('secure-access.team.relationships.destroy', $relationship))
            ->assertRedirect(route('secure-access.team.index'));

        $this->assertSame(1, TeamAuthorityDocument::count());
        $this->assertSame('suspended', TeamAuthorityDocument::firstOrFail()->status);
        $this->assertNotNull($relationship->fresh()->ended_at);
        Storage::disk('local')->assertExists($path);
    }

    public function test_revisions_span_representatives_and_preserve_original_bytes(): void
    {
        $representative = $this->teamUser('agent-one', 'professional');
        $ward = $this->createUser(['username' => 'ward', 'email' => 'ward@example.com', 'name' => 'Jordan Doe']);
        $other = $this->createUser(['username' => 'agent-two', 'email' => 'agent-two@example.com',
            'name' => 'Alex Smith', 'registration_role' => 'family_caregiver']);
        $service = app(\SecureAccess\Services\AuthorityReviewService::class);
        $first = $service->submit($representative, $this->relationship($representative, $ward),
            ['type' => 'power_of_attorney'], $this->pdf());
        $disk = app(\SecureAccess\Services\AuthorityOriginalStorage::class)->disk();
        $bytes = $disk->get($first->path);
        $second = $service->submit($other, $this->relationship($other, $ward),
            ['type' => 'power_of_attorney'], $this->pdf());

        $this->assertSame(1, $first->revision);
        $this->assertSame(2, $second->revision);
        $this->assertStringEndsWith(' - Smith & Doe - POA - Rev2.pdf', $second->file_name);
        $this->assertSame($bytes, $disk->get($first->path));
        $this->assertNotSame($first->path, $second->path);
        $this->assertFileDoesNotExist(public_path('storage/'.$first->path));
        $this->assertDatabaseHas('secure_access_authority_document_audits', ['document_id' => $second->id, 'action' => 'submitted']);
    }

    public function test_original_names_are_sanitized_and_date_uses_application_timezone(): void
    {
        config(['app.timezone' => 'Pacific/Honolulu']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-18T04:00:00Z'));
        $representative = $this->teamUser('agent', 'professional');
        $representative->forceFill(['name' => 'Alex ../../Smith'])->save();
        $ward = $this->createUser(['username' => 'ward', 'email' => 'ward@example.com', 'name' => 'Jordan ..\\Doe']);
        $document = app(\SecureAccess\Services\AuthorityReviewService::class)->submit(
            $representative, $this->relationship($representative, $ward), ['type' => 'guardianship'], $this->pdf()
        );
        $this->assertSame('2026-10-17 - Smith & Doe - Guardianship - Rev1.pdf', $document->file_name);
        $this->assertStringNotContainsString('..', $document->path);
        $this->assertStringNotContainsString('\\', $document->path);
    }

    public function test_identical_names_do_not_collide_between_wards(): void
    {
        $representative = $this->teamUser('agent', 'professional');
        $documents = [];
        foreach ([1, 2] as $number) {
            $ward = $this->createUser(['username' => 'ward-'.$number,
                'email' => 'ward-'.$number.'@example.com', 'name' => 'Jordan Doe']);
            $documents[] = app(\SecureAccess\Services\AuthorityReviewService::class)->submit(
                $representative, $this->relationship($representative, $ward), ['type' => 'guardianship'], $this->pdf()
            );
        }
        $this->assertSame($documents[0]->file_name, $documents[1]->file_name);
        $this->assertNotSame($documents[0]->path, $documents[1]->path);
        $this->assertCount(2, app(\SecureAccess\Services\AuthorityOriginalStorage::class)->disk()->allFiles());
    }

    public function test_mismatched_extension_and_oversize_originals_are_rejected(): void
    {
        $representative = $this->teamUser('agent', 'professional');
        $ward = $this->createUser(['username' => 'ward', 'email' => 'ward@example.com']);
        $relationship = $this->relationship($representative, $ward);
        foreach ([$this->pdf('order.png'), $this->pdf('order.txt'),
            UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')] as $file) {
            $this->post(route('secure-access.team.authority-documents.store', $relationship), [
                'type' => 'guardianship', 'document' => $file,
            ])->assertSessionHasErrors('document');
        }
        $this->assertSame(0, TeamAuthorityDocument::count());
    }

    public function test_storage_refuses_to_overwrite_an_original(): void
    {
        $storage = app(\SecureAccess\Services\AuthorityOriginalStorage::class);
        $path = $storage->path('123', 'guardianship', '2026-10-17 - Smith & Doe - Guardianship - Rev1.pdf');
        $storage->store($this->pdf(), $path);
        $bytes = $storage->disk()->get($path);
        try {
            @$storage->store($this->pdf(), $path);
            $this->fail('An immutable original was overwritten.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($bytes, $storage->disk()->get($path));
        }
    }

    public function test_upgrade_migrates_legacy_originals_without_modifying_their_contents(): void
    {
        Storage::fake('local');
        $representative = $this->teamUser('agent', 'professional');
        $ward = $this->createUser(['username' => 'ward', 'email' => 'ward@example.com']);
        $relationship = $this->relationship($representative, $ward);
        $legacyPath = $this->pdf()->store('secure-access/authority-documents', 'local');
        $bytes = Storage::disk('local')->get($legacyPath);
        $document = TeamAuthorityDocument::create([
            'team_member_id' => $relationship->id, 'type' => 'guardianship',
            'path' => $legacyPath, 'file_name' => 'original.pdf',
        ]);
        $migration = require __DIR__.'/../../database/migrations/2024_01_01_000020_add_authority_original_revisions.php';
        $migration->up();
        $migration->up();
        $document->refresh();
        $this->assertSame(1, $document->revision);
        $this->assertSame((string) $ward->id, $document->ward_id);
        $this->assertSame($bytes, app(\SecureAccess\Services\AuthorityOriginalStorage::class)->disk()->get($document->path));
        $this->assertSame($bytes, Storage::disk('local')->get($legacyPath));
        $this->assertSame(1, $document->audits()->where('action', 'original_migrated')->count());
    }

    public function test_inactive_denied_documents_can_be_resubmitted_as_a_new_revision(): void
    {
        $representative = $this->teamUser('agent', 'professional');
        $ward = $this->createUser(['username' => 'ward', 'email' => 'ward@example.com']);
        $relationship = $this->relationship($representative, $ward);
        $service = app(\SecureAccess\Services\AuthorityReviewService::class);
        $original = $service->submit($representative, $relationship, ['type' => 'guardianship'], $this->pdf());
        $original->forceFill(['review_decision' => 'denied', 'lifecycle_status' => 'inactive', 'status' => 'inactive'])->save();
        $replacement = $service->submit($representative, $relationship, [
            'type' => 'guardianship', 'previous_document_id' => $original->id,
        ], $this->pdf());
        $this->assertSame(2, $replacement->revision);
        $this->assertSame($original->id, $replacement->previous_document_id);
        $this->assertSame('denied', $original->fresh()->review_decision);
    }

    public function test_upgrade_processes_more_than_one_chunk_of_legacy_originals(): void
    {
        Storage::fake('local');
        $representative = $this->teamUser('agent', 'professional');
        $ward = $this->createUser(['username' => 'ward', 'email' => 'ward@example.com']);
        $relationship = $this->relationship($representative, $ward);
        $legacyPath = $this->pdf()->store('secure-access/authority-documents', 'local');
        $rows = array_fill(0, 1001, [
            'team_member_id' => $relationship->id, 'type' => 'guardianship',
            'path' => $legacyPath, 'file_name' => 'original.pdf',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (array_chunk($rows, 100) as $chunk) {
            \Illuminate\Support\Facades\DB::table('secure_access_team_authority_documents')->insert($chunk);
        }
        $migration = require __DIR__.'/../../database/migrations/2024_01_01_000020_add_authority_original_revisions.php';
        $migration->up();
        $this->assertSame(0, TeamAuthorityDocument::whereNull('revision')->count());
        $this->assertSame(1001, TeamAuthorityDocument::max('revision'));
        $this->assertCount(1001, app(\SecureAccess\Services\AuthorityOriginalStorage::class)->disk()->allFiles());
    }
}
