<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\GetCollection;
use App\Controller\TranspileDocumentAction;
use App\Repository\ConversionJobRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\State\ConversionJobPros;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ConversionJobRepository::class)]
#[ORM\Table(name: 'conversion_jobs')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        // ─── RE-ACTIVATE AND SECURE GET COLLECTION ───
        new GetCollection(
            normalizationContext: ['groups' => ['job:read']],
            security: "is_granted('ROLE_USER')"
        ),
        new Get(
            normalizationContext: ['groups' => ['job:read']],
            security: "is_granted('ROLE_USER') and object.getUser() == user"
        ),
        new Patch(
            normalizationContext: ['groups' => ['job:read']],
            security: "is_granted('ROLE_USER') and object.getUser() == user"
        ),
        new Post(
            denormalizationContext: ['groups' => ['job:write']],
            normalizationContext: ['groups' => ['job:read']],
            validationContext: ['groups' => ['job:create']],
            processor: ConversionJobPros::class, // 👈 REGISTERED NATIVELY
            security: "is_granted('ROLE_USER')"
        ),
        // ─── THE STRICT TYPING CONTAINER BYPASS ENGINE (OTHER API) ───
        new Post(
            name: 'transpile_document',
            uriTemplate: '/conversion_jobs/transpile_document',
            controller: TranspileDocumentAction::class,
            deserialize: false,
            security: "is_granted('ROLE_USER')",
            extraProperties: [
                'openapi_context' => [
                    'summary' => 'Transpiles .docx, .pdf or images directly into structural text layers.',
                    'requestBody' => [
                        'content' => [
                            'multipart/form-data' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'file' => ['type' => 'string', 'format' => 'binary'],
                                        'targetFormat' => ['type' => 'string'],
                                        'originType' => ['type' => 'string']
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        )
    ],
    normalizationContext: ['groups' => ['job:read']]
)]
class ConversionJob
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['job:read', 'file:read'])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Template::class)] // 👈 Changed from string 'Template' to Template::class
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['job:read', 'job:write'])]
    #[SerializedName('templateId')]
    private ?Template $template = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['pending', 'processing', 'done', 'failed'], groups: ['job:create', 'job:update'])]
    #[Groups(['job:read'])]
    private string $status = 'pending';

    #[ORM\Column(name: 'conversion_type', length: 50)]
    #[Assert\NotBlank(groups: ['job:create'])]
    #[Groups(['job:read', 'job:write'])]
    #[SerializedName('conversionType')]
    private ?string $conversionType = null;

    // ─── EXPANDED LENGTH & CHOICES FOR OTHER APIS ───
    #[ORM\Column(name: 'source_format', length: 15)]
    #[Assert\NotBlank(groups: ['job:create'])]
    #[Assert\Choice(choices: ['xlsx', 'csv', 'ods', 'pdf', 'docx', 'doc', 'png', 'jpeg', 'jpg', 'webp'], groups: ['job:create'])]
    #[Groups(['job:read', 'job:write'])]
    #[SerializedName('sourceFormat')]
    private ?string $sourceFormat = null;

    // ─── EXPANDED LENGTH & CHOICES FOR OTHER APIS ───
    #[ORM\Column(name: 'target_format', length: 15)]
    #[Assert\NotBlank(groups: ['job:create'])]
    #[Assert\Choice(choices: ['xlsx', 'csv', 'json', 'pdf', 'sql', 'markdown', 'html', 'text'], groups: ['job:create'])] 
    #[Groups(['job:read', 'job:write'])] // 👈 ENSURE THIS LINE IS EXACTLY PRESENT
    #[SerializedName('targetFormat')]
    private ?string $targetFormat = null;

    #[ORM\Column(type: 'json')]
    #[Groups(['job:read', 'job:write'])]
    private array $options = [];

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    #[Groups(['job:read'])]
    #[SerializedName('errorMessage')]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'progress_pct', type: 'smallint')]
    #[Assert\Range(min: 0, max: 100)]
    #[Groups(['job:read'])]
    #[SerializedName('progressPct')]
    private int $progressPct = 0;

    #[ORM\Column(name: 'started_at', type: 'datetimetz_immutable', nullable: true)]
    #[Groups(['job:read'])]
    #[SerializedName('startedAt')]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: 'datetimetz_immutable', nullable: true)]
    #[Groups(['job:read'])]
    #[SerializedName('finishedAt')]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    #[Groups(['job:read'])]
    #[SerializedName('createdAt')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    #[SerializedName('updatedAt')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToMany(mappedBy: 'job', targetEntity: FileTrack::class, cascade: ['remove'])]
    #[Groups(['job:read'])]
    private Collection $files;

    // ─── ADDED ORIGIN TYPE FOR THE OTHER APIS ───
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    #[Groups(['job:read', 'job:write'])]
    private ?string $originType = null; 

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->files = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Getters and Setters
    public function getId(): ?string { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getTemplate(): ?Template { return $this->template; }
    public function setTemplate(?Template $template): static { $this->template = $template; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getConversionType(): ?string { return $this->conversionType; }
    public function setConversionType(string $conversionType): static { $this->conversionType = $conversionType; return $this; }
    public function getSourceFormat(): ?string { return $this->sourceFormat; }
    public function setSourceFormat(string $sourceFormat): static { $this->sourceFormat = $sourceFormat; return $this; }
    public function getTargetFormat(): ?string { return $this->targetFormat; }
    public function setTargetFormat(string $targetFormat): static { $this->targetFormat = $targetFormat; return $this; }
    public function getOptions(): array { return $this->options; }
    public function setOptions(array $options): static { $this->options = $options; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $errorMessage): static { $this->errorMessage = $errorMessage; return $this; }
    public function getProgressPct(): int { return $this->progressPct; }
    public function setProgressPct(int $progressPct): static { $this->progressPct = $progressPct; return $this; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): static { $this->startedAt = $startedAt; return $this; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static { $this->finishedAt = $finishedAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getFiles(): Collection { return $this->files; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static 
    { 
        $this->createdAt = $createdAt; 
        return $this; 
    }
    // ─── ORIGIN TYPE GETTER/SETTER ───
    public function getOriginType(): ?string { return $this->originType; }
    public function setOriginType(?string $originType): static { $this->originType = $originType; return $this; }
}