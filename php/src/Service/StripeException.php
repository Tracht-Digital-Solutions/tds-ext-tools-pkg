<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Service;

use RuntimeException;

/** Raised when the Stripe API rejects a request or is unreachable. Carries the upstream HTTP status. */
final class StripeException extends RuntimeException
{
    public function __construct(string $message, private readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
