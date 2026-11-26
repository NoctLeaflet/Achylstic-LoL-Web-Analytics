<?php

namespace App\Services\Discord;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Posts messages to a Discord webhook. This is the only class that knows the
 * webhook URL.
 *
 * The URL carries a secret token, so it is never logged or put in an exception
 * message (a cURL error message would contain it, which is why connection
 * errors are logged without their text). Sending never throws: a notification
 * that cannot be delivered must not break a sync, it just returns false.
 */
final class DiscordNotifier
{
    private const MAX_ATTEMPTS = 3;

    private const TIMEOUT_SECONDS = 5;

    private const MAX_WAIT_SECONDS = 10;

    /** Discord accepts at most 10 embeds per message. */
    public const MAX_EMBEDS = 10;

    /** True when a valid webhook URL is configured. */
    public function enabled(): bool
    {
        return $this->webhookUrl() !== null;
    }

    /**
     * @param  list<array<string, mixed>>  $embeds
     * @return bool whether Discord accepted the message
     */
    public function send(array $embeds): bool
    {
        $url = $this->webhookUrl();

        if ($url === null || $embeds === []) {
            return false;
        }

        $payload = [
            'username' => (string) config('app.name'),
            'embeds' => array_slice($embeds, 0, self::MAX_EMBEDS),
            // Player names are user-controlled: never let them ping @everyone or roles.
            'allowed_mentions' => ['parse' => []],
        ];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $last = $attempt === self::MAX_ATTEMPTS;

            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)->acceptJson()->asJson()->post($url, $payload);
            } catch (ConnectionException) {
                // Not logging the exception text: it contains the webhook URL.
                Log::warning('Discord webhook unreachable', ['attempt' => $attempt]);

                if ($last) {
                    return false;
                }

                Sleep::for($attempt)->seconds();

                continue;
            }

            if ($response->successful()) {
                return true;
            }

            $status = $response->status();

            if ($status === 429 && ! $last) {
                $wait = (float) ($response->json('retry_after') ?? $response->header('Retry-After') ?: 1);
                Log::warning('Discord webhook rate limited, waiting', ['wait' => $wait]);
                Sleep::for(min(self::MAX_WAIT_SECONDS, max(1, (int) ceil($wait))))->seconds();

                continue;
            }

            if ($status >= 500 && ! $last) {
                Log::warning('Discord server error, retrying', ['status' => $status, 'attempt' => $attempt]);
                Sleep::for($attempt)->seconds();

                continue;
            }

            Log::error('Discord webhook rejected the message', ['status' => $status]);

            return false;
        }

        return false;
    }

    /** The configured URL when it looks like a real Discord webhook, otherwise null. */
    private function webhookUrl(): ?string
    {
        $url = config('services.discord.webhook_url');

        if (blank($url)) {
            return null;
        }

        if (! is_string($url) || ! preg_match('#^https://(?:(?:canary|ptb)\.)?(?:discord|discordapp)\.com/api/(?:v\d+/)?webhooks/\d+/[\w-]+(?:\?.*)?$#', $url)) {
            Log::warning('DISCORD_WEBHOOK_URL is set but is not a valid Discord webhook URL');

            return null;
        }

        return $url;
    }
}
