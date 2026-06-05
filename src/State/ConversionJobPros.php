<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ConversionJob;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
class ConversionJobPros implements ProcessorInterface
{
    public function __construct(
        #[Target('api_platform.doctrine.orm.state.persist_processor')] // 👈 2. TARGET THE CORE DOCTRINE WRITER EXPLICITLY
        private ProcessorInterface $persistProcessor,
        private Security $security
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof ConversionJob) {
            /** @var User|null $user */
            $user = $this->security->getUser();
            
            if ($user) {
                // Securely associate the user to the conversion job before database insertion
                $data->setUser($user);
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}