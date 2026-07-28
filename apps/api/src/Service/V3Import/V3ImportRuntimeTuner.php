<?php

namespace App\Service\V3Import;

use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/**
 * Long-running imports in APP_ENV=dev OOM because the profiler / monolog
 * collectors keep every messenger+doctrine log line in memory.
 */
final class V3ImportRuntimeTuner
{
    public function __construct(
        #[Autowire(service: 'monolog.logger')]
        private readonly LoggerInterface $appLogger,
        #[Autowire(service: 'monolog.logger.messenger')]
        private readonly LoggerInterface $messengerLogger,
        #[Autowire(service: 'monolog.logger.doctrine')]
        private readonly LoggerInterface $doctrineLogger,
        #[Autowire(service: 'monolog.logger.php')]
        private readonly LoggerInterface $phpLogger,
        private readonly ?Profiler $profiler = null,
    ) {
    }

    public function harden(): void
    {
        $this->profiler?->disable();

        foreach ([$this->appLogger, $this->messengerLogger, $this->doctrineLogger, $this->phpLogger] as $logger) {
            if (!$logger instanceof Logger) {
                continue;
            }
            $logger->setHandlers([new NullHandler()]);
            while ($logger->getProcessors() !== []) {
                $logger->popProcessor();
            }
        }
    }
}
