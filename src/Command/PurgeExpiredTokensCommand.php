<?php

namespace App\Command;

use App\Entity\RefreshToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:auth:purge-tokens',
    description: 'Purges expired or explicitly revoked database refresh token strings.',
)]
class PurgeExpiredTokensCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $repo = $this->em->getRepository(RefreshToken::class);
        
        // 1. Build an optimized query to wipe obsolete entries in one operation
        $qb = $repo->createQueryBuilder('t');
        $query = $qb->delete()
            ->where('t.revokedAt IS NOT NULL') // Explicitly logged out records
            ->getQuery();

        $purgedCount = $query->execute();

        $io->success(sprintf('Successfully cleaned up %d obsolete workspace token signatures.', $purgedCount));

        return Command::SUCCESS;
    }
}