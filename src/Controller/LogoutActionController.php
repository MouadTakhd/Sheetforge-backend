<?php

namespace App\Controller;

use App\Entity\RefreshToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class LogoutActionController extends AbstractController
{
    #[Route('/api/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function __invoke(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // 1. Extract the raw token string out of the cookie jar
        $rawRefreshToken = $request->cookies->get('REFRESH_TOKEN');

        if ($rawRefreshToken) {
            // 2. Compute the hash to look up the record securely
            $tokenHash = hash('sha256', $rawRefreshToken);
            
            $refreshTokenRepository = $em->getRepository(RefreshToken::class);
            /** @var RefreshToken|null $tokenRecord */
            $tokenRecord = $refreshTokenRepository->findOneBy(['tokenHash' => $tokenHash]);

            // 3. Revoke the token session if it exists and hasn't been revoked already
            if ($tokenRecord && $tokenRecord->getRevokedAt() === null) {
                $tokenRecord->setRevokedAt(new \DateTimeImmutable());
                $em->flush();
            }
        }

        // 4. Create response and clear the cookie from the client's browser
        $response = new JsonResponse(['message' => 'Workspace session revoked successfully.'], 200);
        $response->headers->clearCookie('REFRESH_TOKEN', '/');

        return $response;
    }
}