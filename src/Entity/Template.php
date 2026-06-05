<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'templates')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(
            normalizationContext: ['groups' => ['template:read']],
            security: "object.isPublic() == true or (is_granted('ROLE_USER') and object.getOwner() == user)"
        ),
        new Post(
            denormalizationContext: ['groups' => ['template:write']],
            normalizationContext: ['groups' => ['template:read']],
            security: "is_granted('ROLE_USER')"
        )
    ]
)]
class Template
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['template:read', 'job:read'])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Groups(['template:read', 'template:write', 'job:read'])]
    private ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['template:read', 'template:write'])]
    private ?string $description = null;

    #[ORM\Column(name: 'mapping_config', type: 'json')]
    #[Groups(['template:read', 'template:write'])]
    #[SerializedName('mappingConfig')]
    private array $mappingConfig = [];

    #[ORM\Column(name: 'is_public', type: 'boolean')]
    #[Groups(['template:read', 'template:write'])]
    #[SerializedName('isPublic')]
    private bool $isPublic = false;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    #[Groups(['template:read'])]
    #[SerializedName('createdAt')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?string { return $this->id; }
    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(?User $user): static { $this->owner = $user; return $this; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function getMappingConfig(): array { return $this->mappingConfig; }
    public function setMappingConfig(array $mappingConfig): static { $this->mappingConfig = $mappingConfig; return $this; }
    public function isPublic(): bool { return $this->isPublic; }
    public function setIsPublic(bool $isPublic): static { $this->isPublic = $isPublic; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}