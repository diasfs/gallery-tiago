<?php

namespace App\Exception;

/**
 * Raised when an admin asks to reprocess a globally disabled AI stage.
 */
final class ProcessingStageDisabledException extends \RuntimeException
{
    public function __construct(
        public readonly string $stage,
        string $message = '',
    ) {
        parent::__construct('' !== $message ? $message : \sprintf('Processing stage "%s" is disabled.', $stage));
    }
}
