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

        $data = json_decode($request->getContent(), true);
        if ($data === null) {
            return $this->json(['message' => 'Invalid JSON payload.'], 400);
        }

        // 1. HARDENED MASS-ASSIGNMENT SECURITY GUARD
        if (array_key_exists('role', $data) || array_key_exists('status', $data) || array_key_exists('email', $data)) {
            return $this->json(['message' => 'Access denied: Cannot mutate administrative or security credentials.'], 403);
        }

        // 2. EXPLICIT PROPERTY INJECTION
        if (array_key_exists('firstName', $data)) {
            $user->setFirstName($data['firstName']);
            
            // Validate specific property manually to completely bypass the email entity lifecycle check
            $errors = $validator->validatePropertyValue($user, 'firstName', $data['firstName'], ['user:update']);
            if (count($errors) > 0) {
                return $this->json(['title' => 'Validation Failed', 'detail' => $errors->get(0)->getMessage(), 'code' => 422], 422);
            }
        }
        
        if (array_key_exists('lastName', $data)) {
            $user->setLastName($data['lastName']);
            
            // Validate specific property manually to completely bypass the email entity lifecycle check
            $errors = $validator->validatePropertyValue($user, 'lastName', $data['lastName'], ['user:update']);
            if (count($errors) > 0) {
                return $this->json(['title' => 'Validation Failed', 'detail' => $errors->get(0)->getMessage(), 'code' => 422], 422);
            }
        }
        
        if (array_key_exists('profilePicture', $data)) {
            $user->setProfilePicture($data['profilePicture']);
        }

        // 3. PERSIST CHANGES CLEANLY
        $em->flush();

        // 4. SERIALIZE ACCORDING TO NORMALIZATION GROUPS
        $jsonResponse = $serializer->serialize($user, 'json', ['groups' => ['user:read']]);
        return new JsonResponse($jsonResponse, 200, [], true);
    }
}