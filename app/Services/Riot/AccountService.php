<?php

namespace App\Services\Riot;

/**
 * account-v1: Riot ID (gameName#tagLine) -> PUUID.
 */
class AccountService
{
    public function __construct(private readonly RiotClient $riot) {}

    /**
     * @return array{puuid: string, game_name: string, tag_line: string}|null null when the Riot ID does not exist
     */
    public function findByRiotId(string $gameName, string $tagLine): ?array
    {
        $data = $this->riot->account(
            '/riot/account/v1/accounts/by-riot-id/'.rawurlencode($gameName).'/'.rawurlencode($tagLine)
        );

        if ($data === null || empty($data['puuid'])) {
            return null;
        }

        return [
            'puuid' => $data['puuid'],
            // Use the canonical casing returned by Riot.
            'game_name' => $data['gameName'] ?? $gameName,
            'tag_line' => $data['tagLine'] ?? $tagLine,
        ];
    }
}
