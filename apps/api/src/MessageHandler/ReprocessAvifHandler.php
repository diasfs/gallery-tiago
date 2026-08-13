<?php

namespace App\MessageHandler;

use App\Exception\ProcessingStageDisabledException;
use App\Message\ReprocessAvifMessage;
use App\Repository\PhotoRepository;
use App\Service\PhotoReprocessor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class ReprocessAvifHandler
{
    // ponytail: 500/job bounds memory; chain another message if idle remain
    private const BATCH = 500;

    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly PhotoReprocessor $reprocessor,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(ReprocessAvifMessage $message): void
    {
        $scope = $message->getScope();

        try {
            foreach ($this->photos->findWithAvifIdleFor($scope, self::BATCH) as $photo) {
                $this->reprocessor->reprocess($photo, $scope);
            }
        } catch (ProcessingStageDisabledException) {
            return;
        }

        if ($this->photos->countWithAvifIdleFor($scope) > 0) {
            $this->bus->dispatch(new ReprocessAvifMessage($scope));
        }
    }
}
