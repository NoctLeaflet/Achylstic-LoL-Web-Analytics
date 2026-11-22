@props(['name', 'title', 'subtitle' => null])

{{--
    A pop-up of the player page. The page owns the Alpine state "panel" (the name of the open pop-up,
    or null), so a button just sets panel = 'mastery' and this closes with Esc, a click outside or the X.
--}}
<div x-show="panel === '{{ $name }}'" x-cloak
     x-transition:enter="transition duration-200 ease-out"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition duration-150 ease-in"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @keydown.escape.window="if (panel === '{{ $name }}') panel = null"
     @click.self="panel = null"
     class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/80 backdrop-blur-sm sm:items-center sm:p-6"
     role="dialog" aria-modal="true" aria-labelledby="panel-{{ $name }}-title">

    <div class="relative flex max-h-[90vh] w-full flex-col overflow-hidden rounded-t-2xl border border-slate-700 bg-slate-900 shadow-2xl shadow-black/50 sm:max-w-3xl sm:rounded-2xl">
        <div class="flex items-start justify-between gap-3 border-b border-slate-800 px-4 pb-3 pt-4 sm:px-6">
            <div>
                <h2 id="panel-{{ $name }}-title" class="text-lg font-semibold text-white">{{ $title }}</h2>
                @if ($subtitle)
                    <p class="text-xs text-slate-500">{{ $subtitle }}</p>
                @endif
            </div>
            <button type="button" @click="panel = null" aria-label="Tutup"
                    class="shrink-0 rounded-lg border border-slate-700 p-1.5 text-slate-400 outline-none transition hover:border-slate-500 hover:text-white focus-visible:ring-2 focus-visible:ring-sky-400">
                <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" /></svg>
            </button>
        </div>

        <div class="overflow-y-auto px-4 py-4 sm:px-6">
            {{ $slot }}
        </div>
    </div>
</div>
