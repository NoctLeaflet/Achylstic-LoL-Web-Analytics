@props(['queue'])

{{-- Solo/Duo <-> Flex switch. Plain links, so the page stays server-rendered and other query parameters are kept. --}}
<div class="inline-flex rounded-lg border border-slate-800 bg-slate-900 p-1 text-sm" aria-label="Pilih antrean ranked">
    @foreach (\App\Enums\RankedQueue::cases() as $option)
        <a href="{{ request()->fullUrlWithQuery(['queue' => $option->slug()]) }}"
           aria-current="{{ $option === $queue ? 'page' : 'false' }}"
           @class([
               'rounded-md px-3 py-1.5 font-medium transition',
               'bg-sky-500/20 text-sky-300' => $option === $queue,
               'text-slate-400 hover:text-slate-200' => $option !== $queue,
           ])>{{ $option->label() }}</a>
    @endforeach
</div>
