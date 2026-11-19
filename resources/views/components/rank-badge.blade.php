@props(['tier', 'rank'])

@php
    // Full class names on purpose: Tailwind only generates classes it can find in the source.
    $classes = match (strtoupper($tier)) {
        'IRON' => 'bg-slate-500/15 text-slate-300 ring-slate-500/30',
        'BRONZE' => 'bg-orange-700/20 text-orange-300 ring-orange-600/30',
        'SILVER' => 'bg-slate-300/10 text-slate-200 ring-slate-300/30',
        'GOLD' => 'bg-yellow-500/15 text-yellow-300 ring-yellow-500/30',
        'PLATINUM' => 'bg-teal-500/15 text-teal-300 ring-teal-500/30',
        'EMERALD' => 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30',
        'DIAMOND' => 'bg-sky-500/15 text-sky-300 ring-sky-500/30',
        'MASTER' => 'bg-purple-500/15 text-purple-300 ring-purple-500/30',
        'GRANDMASTER' => 'bg-red-500/15 text-red-300 ring-red-500/30',
        'CHALLENGER' => 'bg-amber-400/15 text-amber-200 ring-amber-400/40',
        default => 'bg-slate-500/15 text-slate-300 ring-slate-500/30',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold ring-1 ring-inset', $classes]) }}>
    {{ \App\Services\Stats\RankScore::tierLabel($tier, $rank) }}
</span>
