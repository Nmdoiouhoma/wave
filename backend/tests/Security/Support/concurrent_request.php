<?php

declare(strict_types=1);

/*
 * W-26 concurrency worker: boots its own test kernel (own DB connection, outside the DAMA
 * transaction), waits for the shared start time, sends one API request, prints the status.
 * Usage: php concurrent_request.php METHOD URI JWT START_AT [JSON_BODY]
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
new Dotenv()->bootEnv($root.'/.env');

[, $method, $uri, $jwt, $startAt] = $argv;
$body = $argv[5] ?? '';

$kernel = new Kernel('test', false);
$kernel->boot();
while (microtime(true) < (float) $startAt) {
    usleep(500);
}
$request = Request::create($uri, $method, [], [], [], [
    'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
    'REMOTE_ADDR' => '127.0.0.1',
], '' === $body ? null : $body);
$response = $kernel->handle($request);
echo $response->getStatusCode();
$kernel->terminate($request, $response);
