<?php

namespace App\Http\Controllers;

use App\Enums\MatchQueue;
use App\Models\Player;
use App\Services\Stats\GroupBuilds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Build of the members: which runes and items our own players use on a champion,
 * and how well it works for them. Filterable by member and game mode.
 */
class BuildController extends Controller
{
    public function index(Request $request, GroupBuilds $builds): View
    {
        [$mode, $member] = $this->filters($request);

        return view('builds.index', $this->shared($mode, $member) + [
            'champions' => $builds->champions($mode, $member),
        ]);
    }

    public function show(Request $request, int $championId, GroupBuilds $builds): View|RedirectResponse
    {
        [$mode, $member] = $this->filters($request);
        $build = $builds->forChampion($championId, $mode, $member);

        // Nobody (of the chosen member and mode) played it: keep the page and its filters,
        // explain it, and let the visitor widen the selection. Only a champion that nobody
        // in the group ever played at all goes back to the list.
        if ($build === null) {
            $anyone = $builds->forChampion($championId);

            if ($anyone === null) {
                return redirect()->route('builds')->with('notice', 'Champion itu belum pernah dimainkan member.');
            }

            return view('builds.show', $this->shared($mode, $member) + [
                'build' => null,
                'champion' => ['champion_id' => $championId, 'name' => $anyone['name']],
                'thinSample' => GroupBuilds::THIN_SAMPLE,
                'topMinGames' => GroupBuilds::TOP_PLAYER_MIN_GAMES,
            ]);
        }

        return view('builds.show', $this->shared($mode, $member) + [
            'build' => $build,
            'champion' => ['champion_id' => $championId, 'name' => $build['name']],
            'thinSample' => GroupBuilds::THIN_SAMPLE,
            'topMinGames' => GroupBuilds::TOP_PLAYER_MIN_GAMES,
        ]);
    }

    /**
     * @return array{0: MatchQueue|null, 1: Player|null}
     */
    private function filters(Request $request): array
    {
        $mode = MatchQueue::fromSlug($request->query('mode'));
        $mode = in_array($mode, MatchQueue::tracked(), true) ? $mode : null;
        $member = Player::active()->find((int) $request->query('player'));

        return [$mode, $member];
    }

    /**
     * @return array<string, mixed>
     */
    private function shared(?MatchQueue $mode, ?Player $member): array
    {
        return [
            'mode' => $mode,
            'modes' => MatchQueue::tracked(),
            'member' => $member,
            'members' => Player::active()->orderBy('id')->get(),
        ];
    }
}
