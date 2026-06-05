<?php

namespace App\Repository;

use App\Entity\UsageStats;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UsageStatsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UsageStats::class);
    }

    /**
     * Executes a high-performance atomic update query to refresh usage metrics.
     */
    public function incrementStats(string $userId, string $period, bool $isSuccess, int $fileSizeInBytes): void
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            INSERT INTO usage_stats (id, user_id, period, jobs_total, jobs_ok, jobs_failed, bytes_processed, created_at, updated_at)
            VALUES (uuid_generate_v4(), :user_id, :period, 1, :ok_val, :failed_val, :bytes, NOW(), NOW())
            ON CONFLICT (user_id, period) DO UPDATE SET
                jobs_total = usage_stats.jobs_total + 1,
                jobs_ok = usage_stats.jobs_ok + :ok_val,
                jobs_failed = usage_stats.jobs_failed + :failed_val,
                bytes_processed = usage_stats.bytes_processed + :bytes,
                updated_at = NOW()
        ';

        $conn->executeStatement($sql, [
            'user_id'    => $userId,
            'period'     => $period,
            'ok_val'     => $isSuccess ? 1 : 0,
            'failed_val' => $isSuccess ? 0 : 1,
            'bytes'      => $fileSizeInBytes
        ]);
    }
}