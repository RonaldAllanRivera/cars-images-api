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
--}}
<div
    @if ($active) wire:poll.keep-alive.1s="runNextChunk" @endif
    class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            @if ($active)
                <x-filament::loading-indicator class="h-5 w-5 text-primary-500" />
            @endif

            <span class="text-sm font-medium text-gray-950 dark:text-white">
                @if ($blockMessage !== null)
                    Paused — Wikimedia blocked
                @elseif ($active)
                    Running searches
                @else
                    Paused
                @endif
            </span>

            <span class="text-sm text-gray-500 dark:text-gray-400">
                {{ $processed + $failed }} of {{ $total }}
                @if ($failed > 0)
                    · {{ $failed }} failed
                @endif
                @if ($active)
                    · about {{ $eta }} left
                @endif
            </span>
        </div>

        <div class="flex items-center gap-2">
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
        </div>
    </div>

    <div
        class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800"
        role="progressbar"
        aria-valuenow="{{ $percent }}"
        aria-valuemin="0"
        aria-valuemax="100"
    >
        <div
            @class([
                'h-full rounded-full transition-all duration-500',
                'bg-primary-600' => $blockMessage === null,
                'bg-danger-600' => $blockMessage !== null,
            ])
            style="width: {{ $percent }}%"
        ></div>
    </div>

    @if ($blockMessage !== null)
        <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">
            {{ $blockMessage }} — wait for the Retry-After window, then select the remaining rows and run again.
        </p>
    @endif
</div>
