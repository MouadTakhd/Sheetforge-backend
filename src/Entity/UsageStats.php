<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\UsageStatsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UsageStatsRepository::class)]
#[ORM\Table(name: 'usage_stats')]
#[ORM\UniqueConstraint(name: 'unique_user_period', columns: ['user_id', 'period'])]
#[ApiResource(
    operations: [
        new Get(
            normalizationContext: ['groups' => ['stats:read']],
            security: "is_granted('ROLE_USER') and object.getUser() == user"
        ),
        new GetCollection(
            normalizationContext: ['groups' => ['stats:read']],
            security: "is_granted('ROLE_USER')"
        )
    ]
)]
class UsageStats
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['stats:read'])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 7)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[0-9]{4}-[0-9]{2}$/', message: 'The period context format must strictly follow YYYY-MM blueprints.')]
    #[Groups(['stats:read'])]
    private ?string $period = null; // Format context: YYYY-MM (e.g. "2026-06")

    #[ORM\Column(name: 'jobs_total', type: 'integer')]
    #[Groups(['stats:read'])]
    #[SerializedName('jobsTotal')]
    private int $jobsTotal = 0;

    #[ORM\Column(name: 'jobs_ok', type: 'integer')]
    #[Groups(['stats:read'])]
    #[SerializedName('jobsOk')]
    private int $jobsOk = 0;

    #[ORM\Column(name: 'jobs_failed', type: 'integer')]
    #[Groups(['stats:read'])]
    #[SerializedName('jobsFailed')]
    private int $jobsFailed = 0;

    #[ORM\Column(name: 'bytes_processed', type: 'bigint')]
    #[Groups(['stats:read'])]
    #[SerializedName('bytesProcessed')]
    private string $bytesProcessed = '0';

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    #[Groups(['stats:read'])]
    #[SerializedName('createdAt')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    #[Groups(['stats:read'])]
    #[SerializedName('updatedAt')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Getters and Setters
    public function getId(): ?string { return $this->id; }
    
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    
    public function getPeriod(): ?string { return $this->period; }
    public function setPeriod(string $period): static { $this->period = $period; return $this; }
    
    public function getJobsTotal(): int { return $this->jobsTotal; }
    public function setJobsTotal(int $jobsTotal): static { $this->jobsTotal = $jobsTotal; return $this; }
    
    public function getJobsOk(): int { return $this->jobsOk; }
    public function setJobsOk(int $jobsOk): static { $this->jobsOk = $jobsOk; return $this; }
    
    public function getJobsFailed(): int { return $this->jobsFailed; }
    public function setJobsFailed(int $jobsFailed): static { $this->jobsFailed = $jobsFailed; return $this; }
    
    public function getBytesProcessed(): string { return $this->bytesProcessed; }
    public function setBytesProcessed(string $bytesProcessed): static { $this->bytesProcessed = $bytesProcessed; return $this; }
    
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}