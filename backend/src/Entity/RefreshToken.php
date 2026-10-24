<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken as BaseRefreshToken;

/**
 * DATA_MODEL §3: gesdinet mapped superclass; `username` holds the user id (RFC 4122).
 * `valid` stays gesdinet's `timestamp` (Doctrine cannot override an inherited column type);
 * values are written and compared in UTC by the app.
 * ADR 008: `family_id` (= JWT `sid`) groups the tokens of one session; rotated tokens are kept
 * (`rotated_at` set) until expiry so that a replay revokes the whole family.
 */
#[ORM\Entity]
#[ORM\Table(name: 'refresh_token')]
#[ORM\Index(name: 'idx_refresh_token_username', columns: ['username'])]
#[ORM\Index(name: 'idx_refresh_token_family_id', columns: ['family_id'])]
class RefreshToken extends BaseRefreshToken
{
    /** Rotation chain id: every token issued by rotation inherits it (reuse detection, F2). */
    #[ORM\Column(type: 'guid')]
    private string $familyId = '';

    /** Set when the token is rotated; the row is kept until expiry to detect replays. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $rotatedAt = null;

    public function getFamily(): string
    {
        return $this->familyId;
    }

    public function setFamily(string $family): void
    {
        $this->familyId = $family;
    }

    public function getRotatedAt(): ?\DateTimeImmutable
    {
        return $this->rotatedAt;
    }
}
