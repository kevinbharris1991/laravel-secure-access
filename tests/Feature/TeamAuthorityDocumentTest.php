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
                    'document' => UploadedFile::fake()->create('legal-order.pdf', 100, 'application/pdf'),
                ])->assertRedirect(route('secure-access.team.index'));
            }

            $this->assertSame(3, TeamAuthorityDocument::query()->where('team_member_id', $relationship->getKey())->count());
            $this->assertCount(3, Storage::disk('local')->allFiles('secure-access/authority-documents'));
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
        $path = UploadedFile::fake()->create('guardianship.pdf', 100, 'application/pdf')
            ->store('secure-access/authority-documents', 'local');
        $document = TeamAuthorityDocument::query()->create([
            'team_member_id' => $relationship->getKey(),
            'type' => 'guardianship',
            'path' => $path,
            'file_name' => 'guardianship.pdf',
        ]);
        $url = route('secure-access.team.authority-documents.download', [$relationship, $document]);

        $this->actingAs($client, config('secure-access.guard'))->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $client->getKey(),
        ]);
        $this->get($url)->assertOk()->assertHeader('content-disposition', 'attachment; filename=guardianship.pdf');

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

    public function test_removing_team_relationship_also_removes_authority_files(): void
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

        $this->assertSame(0, TeamAuthorityDocument::count());
        Storage::disk('local')->assertMissing($path);
    }
}
