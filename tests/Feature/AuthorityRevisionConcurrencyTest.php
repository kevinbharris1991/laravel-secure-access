<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamMember;
use SecureAccess\Services\AuthorityReviewService;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class AuthorityRevisionConcurrencyTest extends TestCase
{
    protected string $databasePath;
    protected string $privateStoragePath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $this->databasePath = tempnam(sys_get_temp_dir(), 'authority-concurrency-');
        $this->privateStoragePath = sys_get_temp_dir().'/authority-concurrency-'.bin2hex(random_bytes(8));
        $app->useStoragePath($this->privateStoragePath);
        $app['config']->set('database.default', 'authority_concurrency');
        $app['config']->set('database.connections.authority_concurrency', [
            'driver' => 'sqlite', 'database' => $this->databasePath, 'prefix' => '',
            'foreign_key_constraints' => true, 'busy_timeout' => 10000,
        ]);
    }

    public function refreshDatabase(): void
    {
        $this->artisan('migrate:fresh')->run();
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        parent::tearDown();
        File::deleteDirectory($this->privateStoragePath);
        foreach (glob($this->databasePath.'*') ?: [] as $path) {
            unlink($path);
        }
    }

    public function test_simultaneous_representatives_receive_distinct_sequential_revisions(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Concurrent upload coverage requires pcntl and Unix sockets.');
        }
        $ward = $this->createUser(['username' => 'ward', 'email' => 'ward@example.com']);
        $representatives = [];
        foreach ([1, 2] as $number) {
            $representative = $this->createUser([
                'username' => 'agent-'.$number, 'email' => 'agent-'.$number.'@example.com',
                'registration_role' => 'professional',
            ]);
            $relationship = TeamMember::create([
                'self_advocate_id' => (string) $ward->id,
                'team_member_id' => (string) $representative->id,
                'team_member_role' => 'professional',
            ]);
            $representatives[] = [$representative->id, $relationship->id];
        }
        DB::disconnect();
        $workers = [];
        foreach ($representatives as [$userId, $relationshipId]) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                fclose($sockets[0]);
                fread($sockets[1], 1);
                fclose($sockets[1]);
                try {
                    DB::purge();
                    app(AuthorityReviewService::class)->submit(
                        User::findOrFail($userId),
                        TeamMember::findOrFail($relationshipId),
                        ['type' => 'guardianship'],
                        UploadedFile::fake()->createWithContent('order.pdf', "%PDF-1.4\n%%EOF\n")
                    );
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($this->databasePath.'.worker-'.$userId, get_class($exception).': '.$exception->getMessage());
                    exit(1);
                }
            }
            fclose($sockets[1]);
            $workers[] = [$pid, $sockets[0], $userId];
        }
        foreach ($workers as [, $socket]) {
            fwrite($socket, '1');
            fclose($socket);
        }
        foreach ($workers as [$pid, , $userId]) {
            pcntl_waitpid($pid, $status);
            $errorPath = $this->databasePath.'.worker-'.$userId;
            $this->assertSame(0, pcntl_wexitstatus($status), is_file($errorPath) ? file_get_contents($errorPath) : 'Upload worker failed.');
        }
        DB::purge();
        $this->assertSame([1, 2], TeamAuthorityDocument::orderBy('revision')->pluck('revision')->all());
        $this->assertCount(2, app(\SecureAccess\Services\AuthorityOriginalStorage::class)->disk()->allFiles());
    }
}
