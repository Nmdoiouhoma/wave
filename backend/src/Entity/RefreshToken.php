<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken as BaseRefreshToken;

/**
 * DATA_MODEL §3: gesdinet mapped superclass; `username` holds the user id (RFC 4122).
 * `valid` stays gesdinet's `timestamp` (Doctrine cannot override an inherited column type);
 * values are written and compared in UTC by the app.
 */
#[ORM\Entity]
#[ORM\Table(name: 'refresh_token')]
#[ORM\Index(name: 'idx_refresh_token_username', columns: ['username'])]
class RefreshToken extends BaseRefreshToken
{
}
