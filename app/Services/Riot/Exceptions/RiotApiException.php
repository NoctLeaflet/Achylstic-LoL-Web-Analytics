<?php

namespace App\Services\Riot\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base exception for every failed Riot API call. Messages never contain the API key.
 */
class RiotApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }
}
