<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/** DATA_MODEL §2. Counters are updated by repository SQL only, never through setters. */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_app_user_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_app_user_username', columns: ['username'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const array RESERVED_USERNAMES = [
        'me', 'admin', 'administrator', 'api', 'settings', 'search', 'feed', 'login', 'logout', 'register',
        'signup', 'auth', 'root', 'support', 'help', 'about', 'wave', 'moderator', 'mod', 'system', 'null',
        'undefined', 'users', 'artists', 'tracks', 'posts', 'playlists', 'hashtags', 'library', 'upload',
        'explore', 'discover', 'reset-password', 'verify-email', 'stream', 'media', 'static', 'assets',
    ];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: 'citext', length: 180)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column(type: 'citext', length: 30)]
    private string $username;

    #[ORM\Column(length: 50)]
    private string $displayName;

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatarKey = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $roles = [];

    #[ORM\Column(options: ['default' => 0])]
    private int $followersCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $followingCount = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email, string $username, string $displayName)
    {
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->username = strtolower($username);
        $this->displayName = $displayName;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): void
    {
        $this->bio = $bio;
    }

    public function getAvatarKey(): ?string
    {
        return $this->avatarKey;
    }

    /** Media contract (CONVENTIONS §8): called by the ProcessImage handler. */
    public function setAvatarKey(?string $avatarKey): void
    {
        $this->avatarKey = $avatarKey;
    }

    public function getFollowersCount(): int
    {
        return $this->followersCount;
    }

    public function getFollowingCount(): int
    {
        return $this->followingCount;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    public function markEmailVerified(): void
    {
        $this->emailVerifiedAt ??= new \DateTimeImmutable();
    }

    public function getSuspendedAt(): ?\DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    public function isSuspended(): bool
    {
        return null !== $this->suspendedAt;
    }

    public function suspend(): void
    {
        $this->suspendedAt ??= new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isModerator(): bool
    {
        return \in_array('ROLE_MODERATOR', $this->roles, true) || \in_array('ROLE_ADMIN', $this->roles, true);
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        $this->roles = array_values(array_unique(array_diff($roles, ['ROLE_USER'])));
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique(['ROLE_USER', ...$this->roles]));
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function getUserIdentifier(): string
    {
        return $this->id->toRfc4122();
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
