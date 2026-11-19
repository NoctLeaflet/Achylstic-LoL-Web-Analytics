<?php

namespace App\Services\Riot\Exceptions;

/**
 * Riot kept answering 429 even after waiting for Retry-After several times.
 */
class RiotRateLimitException extends RiotApiException
{
    public function __construct()
    {
        parent::__construct('Rate limit Riot API terlampaui setelah beberapa kali retry (HTTP 429).', 429);
    }
}
