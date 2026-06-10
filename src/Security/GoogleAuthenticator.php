<?php
// src/Security/GoogleAuthenticator.php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
// ─── SWAP THIS IMPORT TO THE NEW EMBEDDED AUTHENTICATOR ───
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class GoogleAuthenticator extends OAuth2Authenticator implements AuthenticationEntryPointInterface
{
    private ClientRegistry $clientRegistry;
    private EntityManagerInterface $em;
    private JWTTokenManagerInterface $jwtManager;
    private string $frontendUrl;

    public function __construct(
        ClientRegistry $clientRegistry,
        EntityManagerInterface $em,
        JWTTokenManagerInterface $jwtManager,
        RouterInterface $router,
        string $frontendUrl
    ) {
        // Forward core router engines up to the parent OAuth2 implementation
        parent::__construct($clientRegistry, $router);
        
        $this->clientRegistry = $clientRegistry;
        $this->em = $em;
        $this->jwtManager = $jwtManager;
        $this->frontendUrl = $frontendUrl;
    }

    public function supports(Request $request): ?bool
    {
        // Intercept validation only when landing back on the specific redirect route
        return $request->attributes->get('_route') === 'connect_google_check';
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client) {
                /** @var GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);
                $email = $googleUser->getEmail();

                $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

                if (!$user) {
                    $user = new User();
                    $user->setEmail($email);
                    $user->setFirstName($googleUser->getFirstName());
                    $user->setLastName($googleUser->getLastName());
                    $user->setProfilePicture($googleUser->getAvatar());
                    $user->setPassword(bin2hex(random_bytes(32)));
                    
                    $this->em->persist($user);
                }

                // Force account activation immediately for verified Google handlers
                $user->setIsVerified(true); 
                $this->em->flush();

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        /** @var User $user */
        $user = $token->getUser();
        $jwtToken = $this->jwtManager->create($user);

        $redirectUrl = sprintf('%s/auth?token=%s', rtrim($this->frontendUrl, '/'), $jwtToken);
        return new RedirectResponse($redirectUrl);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $redirectUrl = sprintf('%s/auth?error=%s', rtrim($this->frontendUrl, '/'), urlencode($exception->getMessageKey()));
        return new RedirectResponse($redirectUrl);
    }

    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        // If a request hits a protected API node unauthenticated, push them to login
        $redirectUrl = sprintf('%s/auth', rtrim($this->frontendUrl, '/'));
        return new RedirectResponse($redirectUrl, Response::HTTP_UNAUTHORIZED);
    }
}