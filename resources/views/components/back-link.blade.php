@props(['fallback'])

{{--
    "Kembali" goes to the page the visitor came from. When there is no such page
    (the link was opened directly or in a new tab) it falls back to $fallback.
    Without JavaScript it is simply a link to $fallback.
--}}
<a href="{{ $fallback }}"
   x-data
   @click="
       const cameFromHere = document.referrer && new URL(document.referrer).origin === window.location.origin;
       if (cameFromHere && window.history.length > 1) { $event.preventDefault(); window.history.back(); }
   "
   {{ $attributes->class(['inline-flex items-center gap-1 text-sm text-slate-400 hover:text-slate-200']) }}>
    <span aria-hidden="true">&larr;</span> Kembali
</a>
