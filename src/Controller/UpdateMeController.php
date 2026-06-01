<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class UpdateMeController extends AbstractController
{
    #[Route('/api/me', name: 'api_update_me', methods: ['PATCH'])]
    public function __invoke(
        #[CurrentUser] ?User $user,
        Request $request,
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        ValidatorInterface $validator
    ): JsonResponse {
        if (!$user) {
            return $this->json(['message' => 'JWT Token is missing or invalid.'], 401);
        }

        // Decode the incoming application/merge-patch+json payload
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['message' => 'Invalid JSON payload.'], 400);
        }

        // Dynamically mutate fields only if they exist in the payload
        if (array_key_exists('fullName', $data)) {
            $user->setFullName($data['fullName']);
        }
        
        if (array_key_exists('profilePicture', $data)) {
            $user->setProfilePicture($data['profilePicture']);
        }

        // Run validation rules specifically against the user entity state
        $errors = $validator->validate($user, null, ['user:update']);
        if (count($errors) > 0) {
            return $this->json([
                'title' => 'An error occurred',
                'detail' => $errors->get(0)->getMessage()
            ], 422);
        }

        // Flush directly to database
        $em->flush();

        // Serialize the clean response matching your user:read rules
        $jsonResponse = $serializer->serialize($user, 'json', ['groups' => ['user:read']]);
        return new JsonResponse($jsonResponse, 200, [], true);
    }
}