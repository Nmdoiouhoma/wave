<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Uid\Uuid;

/**
 * W-72: alert dedup under real concurrency (DATA_MODEL §16). COMMITTED events through a separate
 * connection, two worker processes (`Support/evaluate_rules.php`) released at the same instant,
 * each evaluating its own token_reused events of the same user: one open row, every hit counted.
 */
final class AlertConcurrencySecurityTest extends SecurityTestCase
{
    private const string PREFIX = 'w72c';
    private const int PER_WORKER = 5;

    private Connection $raw;

    protected function setUp(): void
    {
        parent::setUp();
        $params = array_filter(
            $this->em()->getConnection()->getParams(),
            static fn (string $k): bool => !str_starts_with($k, 'dama'),
            \ARRAY_FILTER_USE_KEY,
        );
        $this->raw = DriverManager::getConnection($params);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->raw->close();
        parent::tearDown();
    }

    public function testTwoWorkersRaiseOneOpenAlertAndCountEveryHit(): void
    {
        $user = Uuid::v7()->toRfc4122();
        $this->raw->insert('app_user', [
            'id' => $user, 'email' => self::PREFIX.'@example.test', 'password' => 'x', 'username' => self::PREFIX.'_victim',
            'display_name' => 'Victim', 'email_verified_at' => 'now()', 'created_at' => 'now()',
        ]);
        $batches = [[], []];
        for ($i = 0; $i < 2 * self::PER_WORKER; ++$i) {
            $id = Uuid::v7()->toRfc4122();
            $this->raw->insert('activity_event', [
                'id' => $id, 'category' => 'auth', 'type' => 'auth.token_reused', 'subject_user_id' => $user,
                'metadata' => '{}', 'created_at' => 'now()',
            ]);
            $batches[$i % 2][] = $id;
        }

        $startAt = microtime(true) + 4.0;
        $procs = array_map(fn (array $ids): array => $this->spawn($startAt, $ids), $batches);
        foreach ($procs as $p) {
            $this->finish($p);
        }

        $rows = $this->raw->fetchAllAssociative("SELECT status, hit_count FROM security_alert WHERE rule = 'token_reuse' AND subject_user_id = ?", [$user]);
        self::assertSame([['status' => 'open', 'hit_count' => 2 * self::PER_WORKER]], $rows);
    }

    /**
     * @param list<string> $ids
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function spawn(float $startAt, array $ids): array
    {
        $env = getenv() + ['APP_ENV' => 'test'];
        $env['APP_ENV'] = 'test';
        $cmd = [\PHP_BINARY, __DIR__.'/Support/evaluate_rules.php', \sprintf('%.6F', $startAt), ...$ids];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($proc);

        return [$proc, $pipes];
    }

    /** @param array{0: resource, 1: array<int, resource>} $p */
    private function finish(array $p): void
    {
        [$proc, $pipes] = $p;
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        self::assertSame('ok', $out, 'worker failed (exit '.$exit.'): '.substr($err, 0, 2000));
    }

    private function cleanup(): void
    {
        $users = $this->raw->fetchFirstColumn('SELECT id FROM app_user WHERE username LIKE ?', [self::PREFIX.'\_%']);
        foreach ($users as $id) {
            $this->raw->executeStatement('DELETE FROM activity_event WHERE subject_user_id = ? OR actor_id = ?', [$id, $id]);
            $this->raw->executeStatement('DELETE FROM security_alert WHERE subject_user_id = ?', [$id]);
            $this->raw->executeStatement('DELETE FROM app_user WHERE id = ?', [$id]);
        }
    }
}
