<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Artist;
use App\Entity\Genre;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\Visibility;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

abstract class WaveApiTestCase extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected const string PASSWORD = 'correct horse battery';

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    protected function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if (!$em->isOpen()) {
            // A business error thrown inside wrapInTransaction() closes the manager; the
            // kernel is shared between requests here (disableReboot), so reopen it.
            static::getContainer()->get('doctrine')->resetManager();
            $em = static::getContainer()->get(EntityManagerInterface::class);
        }

        return $em;
    }

    protected function createUser(string $username = 'alice', bool $verified = true, ?string $email = null, ?string $displayName = null): User
    {
        $user = new User($email ?? $username.'@example.test', $username, $displayName ?? ucfirst($username));
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        if ($verified) {
            $user->markEmailVerified();
        }
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    protected function createArtist(User $owner, string $name = 'Some Artist', ?string $slug = null): Artist
    {
        $artist = new Artist($owner, $slug ?? strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name)), $name);
        $this->em()->persist($artist);
        $this->em()->flush();

        return $artist;
    }

    protected function createTrack(Artist $artist, string $title = 'Song', bool $ready = true, Visibility $visibility = Visibility::Public, bool $allowClips = true): Track
    {
        $genre = $this->em()->find(Genre::class, 'pop');
        self::assertNotNull($genre);
        $track = new Track($artist, $genre, $title, 1000, $visibility, $allowClips);
        $track->setRawKey('quarantine/tracks/'.$track->getId()->toRfc4122().'/source');
        $this->em()->persist($track);
        $this->em()->flush();
        if ($ready) {
            $track->markReady('audio/tracks/'.$track->getId()->toRfc4122().'.m4a', 180_000);
            $this->em()->flush();
        }

        return $track;
    }

    /**
     * ADR 009 step 1 + the browser's form POST: opens a slot through the API, then writes
     * $bytes at the policy key in the (in-memory) private storage. Returns the uploadId.
     */
    protected function uploadAudio(User $as, string $filename = 'song.mp3', int $bytes = 2048, string $contentType = 'audio/mpeg'): string
    {
        $slot = $this->api('POST', '/api/v1/tracks/uploads', $as, ['json' => ['filename' => $filename, 'size' => $bytes, 'contentType' => $contentType]]);
        self::assertSame(201, $slot->getStatusCode(), $slot->getContent(false));
        $body = $this->json($slot);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, str_repeat("\0", $bytes));
        rewind($stream);
        static::getContainer()->get(\App\Storage\ObjectStorage::class)->write(\App\Storage\StorageArea::Private, $body['fields']['key'], $stream, $contentType);

        return $body['uploadId'];
    }

    protected function tokenFor(User $user): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * @param array<string, mixed> $options HttpClient options (json, headers, body, extra…)
     */
    protected function api(string $method, string $url, ?User $as = null, array $options = []): ResponseInterface
    {
        $headers = $options['headers'] ?? [];
        if (null !== $as) {
            $headers['Authorization'] = 'Bearer '.$this->tokenFor($as);
        }
        $headers['Accept'] ??= 'application/json';
        $options['headers'] = $headers;

        return $this->client->request($method, $url, $options);
    }

    /** @return array<string, mixed>|list<mixed> */
    protected function json(ResponseInterface $response): array
    {
        $content = $response->getContent(false);

        /* @var array<string, mixed>|list<mixed> */
        return '' === $content ? [] : json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
    }

    protected function assertProblem(ResponseInterface $response, int $status, string $code): void
    {
        self::assertSame($status, $response->getStatusCode(), $response->getContent(false));
        self::assertStringStartsWith('application/problem+json', $response->getHeaders(false)['content-type'][0] ?? '');
        $body = $this->json($response);
        self::assertSame($code, $body['code'] ?? null, $response->getContent(false));
        self::assertSame('urn:wave:problem:'.$code, $body['type'] ?? null);
        self::assertSame($status, $body['status'] ?? null);
    }

    /**
     * Messages waiting in a Doctrine transport (`media`, `async`).
     *
     * @template T of object
     *
     * @param class-string<T>|null $class
     *
     * @return ($class is null ? list<object> : list<T>)
     */
    protected function queued(string $transport, ?string $class = null): array
    {
        /** @var ListableReceiverInterface $receiver */
        $receiver = static::getContainer()->get('messenger.transport.'.$transport);
        $messages = array_map(static fn (Envelope $e): object => $e->getMessage(), iterator_to_array($receiver->all(), false));

        return array_values(null === $class ? $messages : array_filter($messages, static fn (object $m): bool => $m instanceof $class));
    }

    /**
     * Runs the queued messages of $class through their handler (what the worker does).
     *
     * @param class-string $class
     */
    protected function runQueued(string $transport, string $class): int
    {
        $count = 0;
        foreach ($this->queued($transport, $class) as $message) {
            static::getContainer()->get('messenger.default_bus')->dispatch($message, [new \Symfony\Component\Messenger\Stamp\ReceivedStamp($transport)]);
            ++$count;
        }

        return $count;
    }

    /** @return list<\Symfony\Component\Mime\Email> */
    protected function sentEmails(): array
    {
        /** @var \Symfony\Component\Mailer\EventListener\MessageLoggerListener $logger */
        $logger = static::getContainer()->get('mailer.message_logger_listener'); // @phpstan-ignore symfonyContainer.serviceNotFound (test env only)
        $emails = [];
        foreach ($logger->getEvents()->getMessages() as $message) {
            if ($message instanceof \Symfony\Component\Mime\Email) {
                $emails[] = $message;
            }
        }

        return $emails;
    }

    /** The test browser keeps cookies between requests; drop them to send explicit ones. */
    protected function forgetCookies(): void
    {
        $this->client->getKernelBrowser()->getCookieJar()->clear();
    }

    /** Replaces the browser cookies with a single `wave_refresh` cookie. */
    protected function useRefreshCookie(string $value): void
    {
        $this->forgetCookies();
        $this->client->getKernelBrowser()->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('wave_refresh', $value, null, '/api/v1/auth'));
    }

    /**
     * Fresh copy from the database (the entity manager is reset between requests).
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    protected function reload(object $entity): object
    {
        $this->em()->clear();
        $id = $this->em()->getClassMetadata($entity::class)->getIdentifierValues($entity);
        $fresh = $this->em()->find($entity::class, $id);
        self::assertNotNull($fresh, 'entity was deleted');

        return $fresh;
    }
}
