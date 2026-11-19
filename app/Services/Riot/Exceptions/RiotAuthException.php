<?php

namespace App\Services\Riot\Exceptions;

/**
 * The API key is missing, expired or rejected (HTTP 401/403).
 * Development keys expire every 24 hours, so this is an expected situation.
 */
class RiotAuthException extends RiotApiException
{
    public static function missingKey(): self
    {
        return new self('RIOT_API_KEY belum diisi di .env.');
    }

    public static function rejected(int $status): self
    {
        return new self(
            "API key kedaluwarsa/invalid (HTTP {$status}). "
            .'Ambil key baru di developer.riotgames.com lalu perbarui RIOT_API_KEY di .env.',
            $status,
        );
    }
}
