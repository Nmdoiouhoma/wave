<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__).'/.env');

// Throwaway JWT key pair for the test environment (gitignored, see config/jwt/*.pem).
$jwtDir = dirname(__DIR__).'/config/jwt';
if (!is_file($jwtDir.'/test_private.pem') || !is_file($jwtDir.'/test_public.pem')) {
    @mkdir($jwtDir, 0o775, true);
    $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    if (false === $key) {
        throw new RuntimeException('Cannot generate the test JWT key pair.');
    }
    openssl_pkey_export($key, $private, 'test-jwt-passphrase');
    $details = openssl_pkey_get_details($key);
    file_put_contents($jwtDir.'/test_private.pem', $private);
    file_put_contents($jwtDir.'/test_public.pem', is_array($details) ? $details['key'] : '');
}

// Fresh schema from the migrations (the source of truth), once per run.
// DAMA DoctrineTestBundle then rolls back every test.
if ('1' !== ($_SERVER['WAVE_TEST_KEEP_DB'] ?? getenv('WAVE_TEST_KEEP_DB'))) {
    $console = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/console').' --env=test --no-interaction --quiet ';
    foreach (['doctrine:database:drop --force --if-exists', 'doctrine:database:create', 'doctrine:migrations:migrate'] as $command) {
        passthru($console.$command, $code);
        if (0 !== $code) {
            throw new RuntimeException('Test database setup failed: '.$command);
        }
    }
}
