<?php

namespace App\EventListener;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: User::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: User::class)]
class UserPasswordHasherListener
{
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public function prePersist(User $user, LifecycleEventArgs $event): void
    {
        $this->hashPassword($user);
    }

    public function preUpdate(User $user, LifecycleEventArgs $event): void
    {
        $this->hashPassword($user);
    }

    private function hashPassword(User $user): void
    {
        $plainPassword = $user->getPassword();

        // Skip if there's no password or if it is already a hash.
        // A simple check: bcrypt/argon hashes are long strings that do not look like plain text,
        // and usually start with $2y$, $argon2id$, etc.
        // If it starts with $ or is empty, we assume it's already hashed or not set.
        if (null === $plainPassword || str_starts_with($plainPassword, '$')) {
            return;
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashedPassword);
    }
}
