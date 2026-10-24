<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Http\ViewNormalizer;
use App\Mapper\UserMapper;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Problem\ProblemResponseFactory;
use Doctrine\DBAL\Connection;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Opens, rotates and revokes sessions (D3):
 * - web (no `X-Wave-Client: mobile`): refresh token only in the `wave_refresh` cookie
 *   (`HttpOnly; Secure`(prod)`; SameSite=Strict; Path=/api/v1/auth`), never in the body;
 * - mobile: refresh token in the JSON body, no cookie.
 * Refresh tokens are single use. Replaying an already-rotated token is treated as theft:
 * the whole family (every descendant) is revoked and 401 `token_reused` answered (ADR 008).
 * The access JWT carries `sid` = family id, so endpoints outside the cookie path
 * (`POST /me/password`) know the current session.
 */
final readonly class SessionIssuer
{
    public const string COOKIE = 'wave_refresh';
    public const string COOKIE_PATH = '/api/v1/auth';

    public function __construct(
        private JWTTokenManagerInterface $jwt,
        private RefreshTokenGeneratorInterface $refreshGenerator,
        private RefreshTokenManagerInterface $refreshManager,
        private UserProvider $users,
        private UserMapper $userMapper,
        private ProblemResponseFactory $problems,
        private Connection $connection,
        #[Autowire(env: 'int:JWT_TTL')]
        private int $accessTtl,
        #[Autowire(env: 'int:REFRESH_TOKEN_TTL')]
        private int $refreshTtl,
        #[Autowire(env: 'bool:REFRESH_COOKIE_SECURE')]
        private bool $cookieSecure,
    ) {
    }

    public static function isMobile(Request $request): bool
    {
        return 'mobile' === strtolower((string) $request->headers->get('X-Wave-Client', ''));
    }

    /** @param string|null $family session (family) to continue; null starts a new one */
    public function open(User $user, Request $request, int $status = Response::HTTP_OK, ?string $family = null): JsonResponse
    {
        $family ??= Uuid::v4()->toRfc4122();
        $refresh = $this->refreshGenerator->createForUserWithTtl($user, $this->refreshTtl);
        if (!$refresh instanceof RefreshToken) {
            throw new \LogicException('gesdinet refresh_token_class must be '.RefreshToken::class);
        }
        $refresh->setFamily($family);
        $this->refreshManager->save($refresh);
        $refreshToken = (string) $refresh->getRefreshToken();

        $body = [
            'accessToken' => $this->jwt->createFromPayload($user, ['sid' => $family]),
            'tokenType' => 'Bearer',
            'expiresIn' => $this->accessTtl,
        ];
        $mobile = self::isMobile($request);
        if ($mobile) {
            $body['refreshToken'] = $refreshToken;
            $body['refreshExpiresIn'] = $this->refreshTtl;
        }
        $body['user'] = ViewNormalizer::toArray($this->userMapper->toMe($user));

        $response = new JsonResponse($body, $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        if (!$mobile) {
            $response->headers->setCookie($this->cookie($refreshToken, $this->refreshTtl));
        }

        return $response;
    }

    /**
     * Rotation: the presented token is marked rotated atomically (UPDATE … WHERE rotated_at
     * IS NULL RETURNING), so two concurrent refreshes with the same token cannot both succeed.
     * A token that was already rotated is a replay: its family is revoked (F2).
     */
    public function rotate(Request $request): Response
    {
        $raw = $this->presentedToken($request);
        if (null === $raw) {
            return $this->invalid($request);
        }
        $row = $this->connection->fetchAssociative(
            'UPDATE refresh_token SET rotated_at = clock_timestamp() WHERE refresh_token = :t AND rotated_at IS NULL RETURNING username, valid, family_id',
            ['t' => $raw],
        );
        if (false === $row) {
            $family = $this->connection->fetchOne('SELECT family_id FROM refresh_token WHERE refresh_token = :t AND rotated_at IS NOT NULL', ['t' => $raw]);
            if (\is_string($family)) {
                $this->connection->executeStatement('DELETE FROM refresh_token WHERE family_id = :f', ['f' => $family]);

                return $this->invalid($request, ProblemCode::TokenReused);
            }

            return $this->invalid($request);
        }
        if (!\is_string($row['username'] ?? null) || !\is_string($row['valid'] ?? null)
            || new \DateTimeImmutable($row['valid']) <= new \DateTimeImmutable()) {
            return $this->invalid($request);
        }

        try {
            $user = $this->users->loadUserByIdentifier($row['username']);
        } catch (\Throwable) {
            return $this->invalid($request);
        }
        if ($user->isSuspended()) {
            $this->revokeAll($user);

            throw new ApiProblemException(ProblemCode::AccountSuspended);
        }

        return $this->open($user, $request, Response::HTTP_OK, \is_string($row['family_id'] ?? null) ? $row['family_id'] : null);
    }

    /**
     * `POST /me/password` (ADR 008): every other session of the user is revoked; the current
     * one (`sid` of the access JWT) is rotated — its live tokens are marked rotated and a new
     * one is issued in the same family. Without a usable `sid`, everything is revoked and a
     * new session is opened.
     */
    public function keepOnlyCurrent(User $user, Request $request): JsonResponse
    {
        $sid = $this->currentSid($request);
        if (null === $sid) {
            $this->revokeAll($user);

            return $this->open($user, $request);
        }
        $this->connection->executeStatement(
            'DELETE FROM refresh_token WHERE username = :u AND family_id <> :f',
            ['u' => $user->getUserIdentifier(), 'f' => $sid],
        );
        $alive = $this->connection->executeStatement(
            'UPDATE refresh_token SET rotated_at = clock_timestamp() WHERE username = :u AND family_id = :f AND rotated_at IS NULL',
            ['u' => $user->getUserIdentifier(), 'f' => $sid],
        );

        // A session already revoked (logged out) is not resurrected.
        return $this->open($user, $request, Response::HTTP_OK, $alive > 0 ? $sid : null);
    }

    /** `sid` claim of the bearer of this request, if it is a valid uuid. */
    private function currentSid(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }
        try {
            $payload = $this->jwt->parse(substr($header, 7));
        } catch (\Throwable) {
            return null;
        }
        $sid = $payload['sid'] ?? null;

        return \is_string($sid) && Uuid::isValid($sid) ? $sid : null;
    }

    public function close(Request $request): Response
    {
        $raw = $this->presentedToken($request);
        if (null !== $raw) {
            // The whole session goes: the presented token and its family.
            $this->connection->executeStatement(
                'DELETE FROM refresh_token WHERE family_id = (SELECT family_id FROM refresh_token WHERE refresh_token = :t)',
                ['t' => $raw],
            );
        }
        $response = new Response(null, Response::HTTP_NO_CONTENT);
        if (!self::isMobile($request)) {
            $response->headers->setCookie($this->cookie('', 0));
        }

        return $response;
    }

    /** Password reset, suspension: every device is logged out at its next refresh. */
    public function revokeAll(User $user): void
    {
        $this->connection->executeStatement('DELETE FROM refresh_token WHERE username = :u', ['u' => $user->getUserIdentifier()]);
    }

    private function invalid(Request $request, ProblemCode $code = ProblemCode::RefreshTokenInvalid): Response
    {
        $response = $this->problems->create($code);
        if (!self::isMobile($request)) {
            $response->headers->setCookie($this->cookie('', 0));
        }

        return $response;
    }

    private function presentedToken(Request $request): ?string
    {
        if (self::isMobile($request)) {
            try {
                $data = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
            $token = \is_array($data) ? ($data['refreshToken'] ?? null) : null;
        } else {
            $token = $request->cookies->get(self::COOKIE);
        }

        return \is_string($token) && '' !== $token && \strlen($token) <= 128 ? $token : null;
    }

    private function cookie(string $value, int $maxAge): Cookie
    {
        return Cookie::create(self::COOKIE)
            ->withValue($value)
            ->withExpires(0 === $maxAge ? 1 : time() + $maxAge)
            ->withPath(self::COOKIE_PATH)
            ->withSecure($this->cookieSecure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT)
            ->withRaw(false);
    }
}
