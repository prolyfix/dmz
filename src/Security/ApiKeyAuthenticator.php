<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\SynstituteInstanceRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly SynstituteInstanceRepository $instanceRepository,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $appEnv,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return str_starts_with($request->getPathInfo(), '/api/')
            && !str_starts_with($request->getPathInfo(), '/api/public/');
    }

    public function authenticate(Request $request): Passport
    {
        $allowInsecure = 'dev' === $this->appEnv && filter_var($_ENV['APP_ALLOW_INSECURE'] ?? '0', FILTER_VALIDATE_BOOL);
        if (!$allowInsecure && !$request->isSecure()) {
            throw new CustomUserMessageAuthenticationException('HTTPS is required.');
        }

        $instanceId = trim((string) $request->headers->get('X-Instance-Id', ''));
        $apiKey = (string) $request->headers->get('X-Api-Key', '');
        $timestamp = (string) $request->headers->get('X-Request-Timestamp', '');
        $requestId = trim((string) $request->headers->get('X-Request-Id', ''));

        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $instanceId)) {
            throw new CustomUserMessageAuthenticationException('Invalid instance identifier.');
        }

        if (strlen($apiKey) < 24 || strlen($apiKey) > 255) {
            throw new CustomUserMessageAuthenticationException('Invalid API key.');
        }

        if (!preg_match('/^\d{10}$/', $timestamp)) {
            throw new CustomUserMessageAuthenticationException('Invalid request timestamp.');
        }

        if (abs(time() - (int) $timestamp) > 300) {
            throw new CustomUserMessageAuthenticationException('Request timestamp expired.');
        }

        if (!preg_match('/^[A-Za-z0-9-]{12,128}$/', $requestId)) {
            throw new CustomUserMessageAuthenticationException('Invalid request id.');
        }

        $cacheKey = 'rid_' . hash('sha256', $instanceId . '|' . $requestId);
        $cacheItem = $this->cache->getItem($cacheKey);
        if ($cacheItem->isHit()) {
            throw new CustomUserMessageAuthenticationException('Replay request denied.');
        }

        $cacheItem->expiresAfter(600);
        $cacheItem->set(true);
        $this->cache->save($cacheItem);

        return new SelfValidatingPassport(new UserBadge($instanceId, function (string $userIdentifier) use ($apiKey) {
            $instance = $this->instanceRepository->findActiveByIdentifier($userIdentifier);
            if (null === $instance) {
                throw new CustomUserMessageAuthenticationException('Unknown instance.');
            }

            if (!password_verify($apiKey, $instance->getApiKeyHash())) {
                throw new CustomUserMessageAuthenticationException('Invalid credentials.');
            }

            return $instance;
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'error' => 'Unauthorized',
        ], Response::HTTP_UNAUTHORIZED);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse([
            'error' => 'Authentication required',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
