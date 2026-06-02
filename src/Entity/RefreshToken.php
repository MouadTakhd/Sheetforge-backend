<?php

namespace App\Entity;

use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'refresh_tokens')]
#[ORM\Index(columns: ['token_hash'], name: 'idx_refresh_token_hash')]
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/refresh-tokens/{id}',
            normalizationContext: ['groups' => ['refresh_token:read']],
            security: "is_granted('ROLE_ADMIN') or object.getUser() == user"
        ),
        new Get(
            uriTemplate: '/me/refresh-tokens',
            provider: CurrentUserRefreshTokensProvider::class,
            uriVariables: [],
            normalizationContext: ['groups' => ['refresh_token:read']],
            security: "is_granted('ROLE_USER')",
            openapi: new Operation(summary: 'Retrieves all active refresh tokens for the currently authenticated user.')
        ),
        new Delete(
            uriTemplate: '/refresh-tokens/{id}',
            security: "is_granted('ROLE_ADMIN') or object.getUser() == user",
            openapi: new Operation(summary: 'Revokes a specific refresh token, effectively logging out that session.')
        )
    ]
    
)]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE', nullable: false)]
    private ?User $user = null;

    #[ORM\Column(name: 'token_hash', length: 255, unique: true)]
    private ?string $tokenHash = null;

    #[ORM\Column(name: 'device_info', length: 255, nullable: true)]
    private ?string $deviceInfo = null;

    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'revoked_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        // Automatically set expiration to 30 days out from generation
        $this->expiresAt = $this->createdAt->modify('+30 days');
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getTokenHash(): ?string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): static
    {
        $this->tokenHash = $tokenHash;
        return $this;
    }

    public function getDeviceInfo(): ?string
    {
        return $this->deviceInfo;
    }

    public function setDeviceInfo(?string $deviceInfo): static
    {
        // Automatically safely truncate user-agent strings exceeding 255 characters
        if ($deviceInfo !== null && strlen($deviceInfo) > 255) {
            $deviceInfo = substr($deviceInfo, 0, 252) . '...';
        }
        
        $this->deviceInfo = $deviceInfo;
        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): static
    {
        $this->revokedAt = $revokedAt;
        return $this;
    }

    /**
     * Helper method to immediately verify if this session is still valid.
     */
    public function isValid(): bool
    {
        return $this->revokedAt === null && $this->expiresAt > new \DateTimeImmutable();
    }
}