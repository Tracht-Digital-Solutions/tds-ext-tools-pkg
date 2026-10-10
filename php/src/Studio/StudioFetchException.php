<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Studio;

use RuntimeException;

/**
 * A refused or failed page analysis. `code` is stable and machine-readable
 * (the studio translates it); the message is the German explanation.
 *
 * Codes: invalid_url, blocked_host, redirect, not_html, too_large, timeout,
 * fetch_failed, rate_limited, disabled.
 */
final class StudioFetchException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $location = null,
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            'invalid_url', 'blocked_host', 'redirect', 'not_html' => 422,
            'too_large' => 413,
            'rate_limited' => 429,
            'disabled' => 403,
            'timeout' => 504,
            default => 502,
        };
    }
}
