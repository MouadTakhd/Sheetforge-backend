<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Repository\ConversionRepository;
use App\State\ConversionOwnerProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ConversionRepository::class)]
#[ApiResource(
    operations: [
        new Get(
            normalizationContext: ['groups' => ['conversion:read']],
            security: "is_granted('ROLE_ADMIN') or object.getUser() == user"
        ),
        new GetCollection(
            normalizationContext: ['groups' => ['conversion:read']],
            security: "is_granted('ROLE_USER')"
        ),
        new Post(
            denormalizationContext: ['groups' => ['conversion:write']],
            normalizationContext: ['groups' => ['conversion:read']],
            security: "is_granted('ROLE_USER')",
            processor: ConversionOwnerProcessor::class
        ),
        new Delete(
            security: "is_granted('ROLE_ADMIN') or object.getUser() == user"
        )
    ]
)]
class Conversion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['conversion:read', 'user:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['conversion:read', 'conversion:write', 'user:read'])]
    private ?string $fileName = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\Positive]
    #[Groups(['conversion:read', 'conversion:write'])]
    private ?int $fileSize = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['conversion:read', 'conversion:write'])]
    private ?string $tableName = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['mysql', 'postgresql', 'sqlite', 'mssql'])]
    #[Groups(['conversion:read', 'conversion:write'])]
    private ?string $dialect = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\Count(min: 1)]
    #[Groups(['conversion:read', 'conversion:write'])]
    private array $columns = [];

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\Count(min: 1)]
    #[Groups(['conversion:read', 'conversion:write'])]
    private array $inferredTypes = [];

    #[ORM\Column]
    #[Assert\NotNull]
    #[Groups(['conversion:read', 'conversion:write'])]
    private array $exclusions = [];

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['conversion:read', 'conversion:write'])]
    private ?string $primaryKey = null;

    #[ORM\Column]
    #[Groups(['conversion:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne(inversedBy: 'conversions')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['conversion:read'])]
    private ?User $user = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(string $fileName): static
    {
        $this->fileName = $fileName;
        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): static
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getTableName(): ?string
    {
        return $this->tableName;
    }

    public function setTableName(string $tableName): static
    {
        $this->tableName = $tableName;
        return $this;
    }

    public function getDialect(): ?string
    {
        return $this->dialect;
    }

    public function setDialect(string $dialect): static
    {
        $this->dialect = $dialect;
        return $this;
    }

    public function getColumns(): array
    {
        return $this->columns;
    }

    public function setColumns(array $columns): static
    {
        $this->columns = $columns;
        return $this;
    }

    public function getInferredTypes(): array
    {
        return $this->inferredTypes;
    }

    public function setInferredTypes(array $inferredTypes): static
    {
        $this->inferredTypes = $inferredTypes;
        return $this;
    }

    public function getExclusions(): array
    {
        return $this->exclusions;
    }

    public function setExclusions(array $exclusions): static
    {
        $this->exclusions = $exclusions;
        return $this;
    }

    public function getPrimaryKey(): ?string
    {
        return $this->primaryKey;
    }

    public function setPrimaryKey(?string $primaryKey): static
    {
        $this->primaryKey = $primaryKey;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
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
}
