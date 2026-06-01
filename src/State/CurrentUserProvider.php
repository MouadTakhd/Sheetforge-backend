<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Automatically intercepts /api/me requests and injects the active JWT identity object.
 */
final readonly class CurrentUserProvider implements ProviderInterface
{
    public function __construct(
        private Security $security
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?User
    {
        /** @var User|null $user */
        $user = $this->security->getUser();

        return $user;
    }
}