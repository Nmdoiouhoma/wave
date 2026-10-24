<?php

declare(strict_types=1);

namespace App\Security;

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

/**
 * Opens, rotates and revokes sessions (D3):
 * - web (no `X-Wave-Client: mobile`): refresh token only in the `wave_refresh` cookie
 *   (`HttpOnly; Secure`(prod)`; SameSite=Strict; Path=/api/v1/auth`), never in the body;
 * - mobile: refresh token in the JSON body, no cookie.
 * Refresh tokens are single use: a rotated or revoked token answers 401.
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

    public function open(User $user, Request $request, int $status = Response::HTTP_OK): JsonResponse
    {
        $refresh = $this->refreshGenerator->createForUserWithTtl($user, $this->refreshTtl);
        $this->refreshManager->save($refresh);
        $refreshToken = (string) $refresh->getRefreshToken();

        $body = [
            'accessToken' => $this->jwt->create($user),
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
     * Rotation: the presented token is consumed atomically (DELETE … RETURNING), so two
     * concurrent refreshes with the same token cannot both succeed.
     */
    public function rotate(Request $request): Response
    {
        $raw = $this->presentedToken($request);
        $row = null === $raw ? false : $this->connection->fetchAssociative(
            'DELETE FROM refresh_token WHERE refresh_token = :t RETURNING username, valid',
            ['t' => $raw],
        );
        if (false === $row || !\is_string($row['username'] ?? null) || !\is_string($row['valid'] ?? null)
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

        return $this->open($user, $request);
    }

    public function close(Request $request): Response
    {
        $raw = $this->presentedToken($request);
        $stored = null === $raw ? null : $this->refreshManager->get($raw);
        if (null !== $stored) {
            $this->refreshManager->delete($stored);
        }
        $response = new Response(null, Response::HTTP_NO_CONTENT);
        if (!self::isMobile($request)) {
            $response->headers->setCookie($this->cookie('', 0));
        }

        return $response;
    }

    /** Password change/reset, suspension: every device is logged out at its next refresh. */
    public function revokeAll(User $user): void
    {
        $this->connection->executeStatement('DELETE FROM refresh_token WHERE username = :u', ['u' => $user->getUserIdentifier()]);
    }

    private function invalid(Request $request): Response
    {
        $response = $this->problems->create(ProblemCode::RefreshTokenInvalid);
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
