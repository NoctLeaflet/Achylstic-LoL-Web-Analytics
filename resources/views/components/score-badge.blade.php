@props(['badge'])

{{-- MVP = best player of the winning team, ACE = best player of the losing team --}}
<span {{ $attributes->class([
    'inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ring-1 ring-inset',
    'bg-amber-400/15 text-amber-300 ring-amber-400/40' => $badge === 'MVP',
    'bg-slate-400/15 text-slate-200 ring-slate-400/40' => $badge !== 'MVP',
]) }} title="{{ $badge === 'MVP' ? 'Pemain terbaik tim pemenang' : 'Pemain terbaik tim yang kalah' }}">{{ $badge }}</span>
