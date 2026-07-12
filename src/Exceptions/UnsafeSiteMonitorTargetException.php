<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Exceptions;

use RuntimeException;

final class UnsafeSiteMonitorTargetException extends RuntimeException
{
    public function __construct(
        public readonly string $errorType,
        string $message,
    ) {
        parent::__construct($message);
    }
}
