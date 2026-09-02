@php
    $percent = $total > 0 ? (int) round(100 * ($processed + $failed) / $total) : 0;
    $minutes = intdiv($secondsRemaining, 60);
    $seconds = $secondsRemaining % 60;
    $eta = $minutes > 0 ? "{$minutes}m {$seconds}s" : "{$seconds}s";
@endphp

{{--
    Drives the bulk run and reports it.

    `keep-alive` is the load-bearing modifier: Livewire pauses ordinary polling
    when the tab loses focus, which would stall a run the moment the admin
    switches away — the exact thing this feature exists to avoid. Chrome still
    throttles background timers, so a hidden run spaces its chunks out, but it
    keeps advancing and still announces itself when it lands.

    Built from Filament's own components: the panel loads only Filament's
    precompiled CSS, with no app Tailwind build in deployment, so utility
    classes such as `flex`, `p-4` or `h-2` resolve to nothing here. The bar is
    inline-styled against the palette's custom properties for the same reason.
--}}
{{-- The poll rides a plain wrapper: a conditional attribute among a
     component tag's own attributes stops Blade parsing the tag as a
     component, and the mismatched close is a compile error. --}}
<div @if ($active) wire:poll.keep-alive.1s="runNextChunk" @endif>
<x-filament::callout
    :color="$blockMessage !== null ? 'danger' : ($active ? 'primary' : 'gray')"
    :icon="$blockMessage !== null ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-arrow-path'"
    :heading="$blockMessage !== null ? 'Paused — Wikimedia blocked' : ($active ? 'Running searches' : 'Paused')"
>
    <x-slot name="description">
        {{ $processed + $failed }} of {{ $total }}

        @if ($failed > 0)
            &middot; {{ $failed }} failed
        @endif

        @if ($active)
            &middot; about {{ $eta }} left
        @endif

        <span
            role="progressbar"
            aria-valuenow="{{ $percent }}"
            aria-valuemin="0"
            aria-valuemax="100"
            style="display:block;height:.375rem;margin-top:.5rem;border-radius:9999px;background:rgba(128,128,128,.25);overflow:hidden"
        >
            <span
                style="display:block;height:100%;width:{{ $percent }}%;border-radius:9999px;transition:width .5s;background:{{ $blockMessage !== null ? 'var(--danger-600)' : 'var(--primary-600)' }}"
            ></span>
        </span>

        @if ($blockMessage !== null)
            <span style="display:block;margin-top:.5rem">
                {{ $blockMessage }} — wait for the Retry-After window, then use
                &ldquo;Run all pending&rdquo; to pick up where this left off.
            </span>
        @endif
    </x-slot>

    <x-slot name="controls">
        @if ($active)
            <x-filament::button size="sm" color="gray" wire:click="pauseBulkRun">
                Pause
            </x-filament::button>
        @elseif ($blockMessage === null)
            <x-filament::button size="sm" color="primary" wire:click="resumeBulkRun">
                Resume
            </x-filament::button>
        @endif

        <x-filament::button size="sm" color="gray" wire:click="cancelBulkRun">
            Dismiss
        </x-filament::button>
    </x-slot>
</x-filament::callout>
</div>
