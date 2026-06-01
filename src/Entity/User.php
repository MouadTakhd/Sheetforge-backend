<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Repository\UserRepository;
use App\State\CurrentUserProvider;
use App\State\UserPasswordHasherStateProcessor;
use ApiPlatform\OpenApi\Model\Operation;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection; 
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')] // Aligned with your database table reference
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email.')]
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/users/{id}',
            normalizationContext: ['groups' => ['user:read']],
            security: "is_granted('ROLE_ADMIN') or object == user"
        ),
        new Get(
            uriTemplate: '/me',
            provider: CurrentUserProvider::class,
            uriVariables: [],
            normalizationContext: ['groups' => ['user:read']],
            security: "is_granted('ROLE_USER')",
            openapi: new Operation(summary: 'Retrieves the currently authenticated user profile.')
        ),
        new Post(
            denormalizationContext: ['groups' => ['user:write']],
            normalizationContext: ['groups' => ['user:read']],
            validationContext: ['groups' => ['user:create']], // Triggers validation rules properly
            processor: UserPasswordHasherStateProcessor::class,
            openapi: new Operation(summary: 'Registers a new user inside the database space.')
        )
    ]
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')] // Use UUIDs instead of auto-increment integers
    #[Groups(['user:read', 'conversion:read'])]
    private ?string $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(groups: ['user:create', 'user:update'])]
    #[Assert\Email(groups: ['user:create', 'user:update'])]
    #[Groups(['user:read', 'user:write', 'conversion:read'])]
    private ?string $email = null;

    #[ORM\Column(name: 'password_hash', length: 255)] // Maps password field explicitly to password_hash
    private ?string $password = null;

    #[Assert\NotBlank(groups: ['user:create'])]
    #[Assert\Length(min: 8, minMessage: 'Your password must be at least {{ limit }} characters long.', groups: ['user:create'])]
    #[Groups(['user:write'])]
    private ?string $plainPassword = null;

    #[ORM\Column(name: 'first_name', length: 100)]
    #[Assert\NotBlank(groups: ['user:create', 'user:update'])]
    #[Groups(['user:read', 'user:write'])]
    private ?string $firstName = null;

    #[ORM\Column(name: 'last_name', length: 100)]
    #[Assert\NotBlank(groups: ['user:create', 'user:update'])]
    #[Groups(['user:read', 'user:write'])]
    private ?string $lastName = null;

    #[ORM\Column(length: 20)]
    #[Groups(['user:read'])]
    private string $role = 'ROLE_USER';

    #[ORM\Column(length: 20)]
    #[Groups(['user:read'])]
    private string $status = 'active'; // active | pending | suspended

    #[ORM\Column(length: 10, options: ['default' => 'en'])]
    private string $locale = 'en';

    #[ORM\Column(length: 50, options: ['default' => 'UTC'])]
    private string $timezone = 'UTC';

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null; // Used for Soft Delete

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower(trim($email)); // Matches the lowercase/trimmed schema rule
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        return [$this->role];
    }

    public function setRoles(array $roles): static
    {
        if (!empty($roles)) {
            $this->role = $roles[0]; // Framework wrapper logic fallback
        }
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;
        return $this;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }

    // Helper method to keep a computed full name if your UI depends on it
    #[Groups(['user:read'])]
    public function getFullName(): string
    {
        return sprintf('%s %s', $this->firstName, $this->lastName);
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;
        return $this;
    }
}