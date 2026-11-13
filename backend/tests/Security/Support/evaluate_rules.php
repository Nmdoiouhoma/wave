<?php

declare(strict_types=1);

/*
 * W-72 concurrency worker: boots its own test kernel (own DB connection, outside the DAMA
 * transaction), waits for the shared start time, then runs EvaluateSecurityRulesHandler on each
 * event id given, as a messenger worker would. Prints "ok".
 * Usage: php evaluate_rules.php START_AT EVENT_ID [EVENT_ID...]
 */

use App\Kernel;
use App\Message\EvaluateSecurityRules;
use App\MessageHandler\EvaluateSecurityRulesHandler;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Dotenv\Dotenv;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
new Dotenv()->bootEnv($root.'/.env');

$startAt = (float) $argv[1];
$ids = array_slice($argv, 2);

$kernel = new Kernel('test', false);
$kernel->boot();
/** @var Connection $connection */
$connection = $kernel->getContainer()->get('doctrine')->getConnection();
$handler = new EvaluateSecurityRulesHandler($connection, new NativeClock());
while (microtime(true) < $startAt) {
    usleep(500);
}
foreach ($ids as $id) {
    $handler(new EvaluateSecurityRules($id));
}
echo 'ok';
