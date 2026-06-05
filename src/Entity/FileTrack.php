<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'files')]
#[ApiResource(
    operations: [
        new Get(
            normalizationContext: ['groups' => ['file:read']],
            security: "is_granted('ROLE_USER') and object.getUser() == user"
        )
    ]
)]
class FileTrack
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['file:read', 'job:read'])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: ConversionJob::class, inversedBy: 'files')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ConversionJob $job = null;

    #[ORM\Column(length: 10)]
    #[Assert\Choice(choices: ['input', 'output'])]
    #[Groups(['file:read', 'job:read'])]
    private ?string $role = null;

    #[ORM\Column(name: 'original_name', length: 255)]
    #[Groups(['file:read', 'job:read'])]
    #[SerializedName('originalName')]
    private ?string $originalName = null;

    #[ORM\Column(name: 'storage_key', length: 500, unique: true)]
    #[Groups(['file:read'])]
    #[SerializedName('storageKey')]
    private ?string $storageKey = null;

    #[ORM\Column(name: 'mime_type', length: 100)]
    #[Groups(['file:read', 'job:read'])]
    #[SerializedName('mimeType')]
    private ?string $mimeType = null;

    #[ORM\Column(name: 'size_bytes', type: 'bigint')]
    #[Groups(['file:read', 'job:read'])]
    #[SerializedName('sizeBytes')]
    private ?string $sizeBytes = null;

    #[ORM\Column(length: 64)]
    #[Groups(['file:read'])]
    private ?string $checksum = null;

    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
    #[Groups(['file:read'])]
    #[SerializedName('expiresAt')]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    #[Groups(['file:read'])]
    #[SerializedName('createdAt')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getJob(): ?ConversionJob { return $this->job; }
    public function setJob(?ConversionJob $job): static { $this->job = $job; return $this; }
    public function getRole(): ?string { return $this->role; }
    public function setRole(string $role): static { $this->role = $role; return $this; }
    public function getOriginalName(): ?string { return $this->originalName; }
    public function setOriginalName(string $originalName): static { $this->originalName = $originalName; return $this; }
    public function getStorageKey(): ?string { return $this->storageKey; }
    public function setStorageKey(string $storageKey): static { $this->storageKey = $storageKey; return $this; }
    public function getMimeType(): ?string { return $this->mimeType; }
    public function setMimeType(string $mimeType): static { $this->mimeType = $mimeType; return $this; }
    public function getSizeBytes(): ?string { return $this->sizeBytes; }
    public function setSizeBytes(string $sizeBytes): static { $this->sizeBytes = $sizeBytes; return $this; }
    public function getChecksum(): ?string { return $this->checksum; }
    public function setChecksum(string $checksum): static { $this->checksum = $checksum; return $this; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): static { $this->expiresAt = $expiresAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}