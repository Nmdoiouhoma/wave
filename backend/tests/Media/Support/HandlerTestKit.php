<?php

declare(strict_types=1);

namespace App\Tests\Media\Support;

use App\Entity\Artist;
use App\Entity\Genre;
use App\Entity\Track;
use App\Entity\User;
use App\Storage\FlysystemObjectStorage;
use App\Storage\StorageArea;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Wiring for media handler tests without a database: real entities, the real
 * FlysystemObjectStorage on in-memory adapters, and an EntityManager stub
 * backed by an identity map (find/refresh/wrapInTransaction).
 */
final class HandlerTestKit
{
    public readonly FlysystemObjectStorage $storage;
    public readonly Filesystem $private;
    public readonly Filesystem $public;
    public readonly EntityManagerInterface&Stub $em;
    public readonly MessageBusInterface $bus;

    /** @var array<string, object> class#id => entity */
    private array $entities = [];
    /** @var list<object> */
    public array $dispatched = [];

    public function __construct(EntityManagerInterface&Stub $em)
    {
        $this->private = new Filesystem(new InMemoryFilesystemAdapter());
        $this->public = new Filesystem(new InMemoryFilesystemAdapter());
        $this->storage = new FlysystemObjectStorage($this->private, $this->public, 'http://localhost:8090/media');

        $em->method('find')->willReturnCallback(fn (string $class, mixed $id): ?object => $this->entities[$class.'#'.(string) $id] ?? null);
        $em->method('wrapInTransaction')->willReturnCallback(fn (callable $fn): mixed => $fn($em));
        $em->method('isOpen')->willReturn(true);
        $this->em = $em;

        $this->bus = new class($this) implements MessageBusInterface {
            public function __construct(private readonly HandlerTestKit $kit)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->kit->dispatched[] = $message;

                return Envelope::wrap($message, $stamps);
            }
        };
    }

    public function persist(object $entity, Uuid $id): void
    {
        $this->entities[$entity::class.'#'.(string) $id] = $entity;
    }

    public function remove(object $entity, Uuid $id): void
    {
        unset($this->entities[$entity::class.'#'.(string) $id]);
    }

    public function user(): User
    {
        $user = new User('artist@example.test', 'artist', 'Artist');
        $this->persist($user, $user->getId());

        return $user;
    }

    public function artist(?User $owner = null): Artist
    {
        $artist = new Artist($owner ?? $this->user(), 'artist', 'Artist');
        $this->persist($artist, $artist->getId());

        return $artist;
    }

    /** A processing track whose raw upload is the given local file. */
    public function track(string $sourceFile): Track
    {
        $id = Uuid::v7();
        $track = new Track($this->artist(), new Genre('ambient', 'Ambient', 1), 'Sine', (int) filesize($sourceFile), id: $id);
        $rawKey = \sprintf('quarantine/tracks/%s/source', $id->toRfc4122());
        $track->setRawKey($rawKey);
        $this->putPrivate($rawKey, $sourceFile);
        $this->persist($track, $id);

        return $track;
    }

    public function putPrivate(string $key, string $file): void
    {
        $this->private->write($key, (string) file_get_contents($file));
    }

    public function has(StorageArea $area, string $key): bool
    {
        return $this->storage->exists($area, $key);
    }
}
