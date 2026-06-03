<?php

namespace App\EventListener;

use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsEventListener(event: 'lexik_jwt_authentication.on_authentication_success')]
class AuthenticationSuccessListener
{
    public function __construct(
        private EntityManagerInterface $em,
        private RequestStack $requestStack // Safe request extractor injected here
    ) {}

    public function __invoke(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $response = $event->getResponse();
        
        // Extract the current master request cleanly from the stack container
        $request = $this->requestStack->getCurrentRequest();
        $userAgent = $request ? $request->headers->get('User-Agent') : null;

        // 1. Generate a cryptographically secure random string
        $rawRefreshToken = bin2hex(random_bytes(32));

        // 2. Hash it using SHA-256 before saving to the database
        $tokenHash = hash('sha256', $rawRefreshToken);

        // 3. Build and persist the RefreshToken entity matching your schema
        $refreshToken = new RefreshToken();
        $refreshToken->setUser($user);
        $refreshToken->setTokenHash($tokenHash);
        $refreshToken->setDeviceInfo($userAgent);

        $this->em->persist($refreshToken);
        $this->em->flush();

        // 4. Inject the raw token into an HttpOnly cookie
        $response->headers->setCookie(
            new Cookie(
                'REFRESH_TOKEN',                  // Cookie name
                $rawRefreshToken,                 // Raw value string
                new \DateTimeImmutable('+30 days'), // Expiration
                '/',                              // Path
                null,                             // Domain
                true,                             // Secure (HTTPS only in prod)
                true,                             // HttpOnly (Invisible to JS)
                false,                            // Raw
                Cookie::SAMESITE_LAX              // CSRF defense mitigation layer
            )
        );
    }
}