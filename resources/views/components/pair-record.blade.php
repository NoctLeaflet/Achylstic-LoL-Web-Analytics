@props(['record', 'empty' => 'Belum pernah'])

{{-- A win/loss record from PairStats: "3M 1K 75.0% (4 game)". Empty when the pair never played. --}}
@if ($record['games'] === 0)
    <span class="text-slate-600">{{ $empty }}</span>
@else
    <span class="tabular-nums text-slate-200">{{ $record['wins'] }}M {{ $record['losses'] }}K</span>
    <span @class([
        'text-xs tabular-nums',
        'text-emerald-400' => $record['winrate'] >= 50,
        'text-rose-400' => $record['winrate'] < 50,
    ])>{{ \App\Support\Format::number($record['winrate']) }}%</span>
    <span class="text-xs tabular-nums text-slate-500">({{ $record['games'] }} game)</span>
@endif
