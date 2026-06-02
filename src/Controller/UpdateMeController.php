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
    #[Route('/api/me', name: 'api_update_me', methods: ['PATCH', 'PUT'])]
    public function __invoke(
        #[CurrentUser] ?User $user,
        Request $request,
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        ValidatorInterface $validator
    ): JsonResponse {
        // 1. Validate active session matrix existence
        if (!$user) {
            return $this->json(['message' => 'JWT Token is missing or invalid.'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if ($data === null) {
            return $this->json(['message' => 'Invalid JSON payload structure.'], 400);
        }

        // 2. HARDENED MASS-ASSIGNMENT GUARD
        // Block mutations to security permissions, clearance roles, or status tracking arrays
        $protectedFields = ['role', 'roles', 'status', 'id', 'email', 'password', 'passwordHash'];
        foreach ($protectedFields as $field) {
            if (array_key_exists($field, $data)) {
                return $this->json([
                    'message' => 'Access denied: Cannot mutate administrative or security parameters.'
                ], 403);
            }
        }

        // 3. SECURE PROPERTY INJECTION (camelCase Frontend aligned)
        if (array_key_exists('firstName', $data)) {
            $user->setFirstName($data['firstName']);
        }
        
        if (array_key_exists('lastName', $data)) {
            $user->setLastName($data['lastName']);
        }
        
        if (array_key_exists('profilePicture', $data)) {
            $user->setProfilePicture($data['profilePicture']);
        }

        // 4. UNIFIED CONSTRAINTS VALIDATION
        // Passing 'user:update' alongside your entity validation rules
        $errors = $validator->validate($user, null, ['user:update']);
        if (count($errors) > 0) {
            return $this->json([
                'title' => 'An error occurred',
                'detail' => sprintf('%s: %s', $errors->get(0)->getPropertyPath(), $errors->get(0)->getMessage()),
                'code' => 422
            ], 422);
        }

        // 5. PERSIST TO INFRASTRUCTURE GRID
        $em->flush();

        // 6. SERIALIZE ACCORDING TO YOUR NORMALIZATION GROUPS
        $jsonResponse = $serializer->serialize($user, 'json', ['groups' => ['user:read']]);
        return new JsonResponse($jsonResponse, 200, [], true);
    }
}