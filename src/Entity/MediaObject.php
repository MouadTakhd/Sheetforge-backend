<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Controller\UploadMediaObjectAction;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\SerializedName;

#[ORM\Entity]
#[ORM\Table(name: 'media_objects')]
#[ApiResource(
    operations: [
        new Get(
            normalizationContext: ['groups' => ['media:read']],
            security: "is_granted('ROLE_USER') and (object.getUser() == user or object.getIsPublic() == true)"
        ),
        new Post(
            controller: UploadMediaObjectAction::class,
            deserialize: false, //  1. Tell API Platform to not look for a matching JSON model
            validate: false,    //  2. Skip auto validation assertions
            defaults: [
                '_api_receive' => false, //  3. CRITICAL: Tells API Platform to leave the raw request body stream alone
            ],
            extraProperties: [
                'use_symfony_listeners' => true //  4. CRITICAL: Hands routing back to native Symfony kernel core listeners
            ],
            normalizationContext: ['groups' => ['media:read']],
            security: "is_granted('ROLE_USER')"
        )
    ]
)]
class MediaObject
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['media:read', 'user:read', 'job:read'])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: ConversionJob::class, inversedBy: 'files')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['media:read'])]
    private ?ConversionJob $job = null;

    #[ORM\Column(length: 15, options: ['default' => 'avatar'])]
    #[Groups(['media:read', 'job:read'])]
    private string $role = 'avatar'; // 'avatar' | 'input' | 'output'

    #[ORM\Column(name: 'file_name', length: 255)]
    #[Groups(['media:read', 'user:read', 'job:read'])]
    private ?string $fileName = null;

    #[ORM\Column(name: 'file_path_url', length: 500, unique: true)]
    #[Groups(['media:read', 'user:read', 'job:read'])]
    #[SerializedName('contentUrl')]
    private ?string $filePathUrl = null;

    #[ORM\Column(name: 'mime_type', length: 100)]
    #[Groups(['media:read', 'job:read'])]
    private ?string $mimeType = null;

    #[ORM\Column(name: 'size_bytes', type: 'bigint', nullable: true)]
    #[Groups(['media:read', 'job:read'])]
    private ?string $sizeBytes = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $checksum = null;

    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    #[Groups(['media:read', 'job:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getJob(): ?ConversionJob { return $this->job; }
    public function setJob(?ConversionJob $job): static { $this->job = $job; return $this; }
    public function getRole(): string { return $this->role; }
    public function setRole(string $role): static { $this->role = $role; return $this; }
    public function getFileName(): ?string { return $this->fileName; }
    public function setFileName(string $fileName): static { $this->fileName = $fileName; return $this; }
    public function getFilePathUrl(): ?string { return $this->filePathUrl; }
    public function setFilePathUrl(string $filePathUrl): static { $this->filePathUrl = $filePathUrl; return $this; }
    public function getMimeType(): ?string { return $this->mimeType; }
    public function setMimeType(string $mimeType): static { $this->mimeType = $mimeType; return $this; }
    public function getSizeBytes(): ?string { return $this->sizeBytes; }
    public function setSizeBytes(?string $sizeBytes): static { $this->sizeBytes = $sizeBytes; return $this; }
    public function getChecksum(): ?string { return $this->checksum; }
    public function setChecksum(?string $checksum): static { $this->checksum = $checksum; return $this; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static { $this->expiresAt = $expiresAt; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getIsPublic(): bool { return $this->role === 'avatar'; }
}