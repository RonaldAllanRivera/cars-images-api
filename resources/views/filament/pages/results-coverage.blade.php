@php
    $percent = $coverage['total'] > 0
        ? (int) round(100 * $coverage['searched'] / $coverage['total'])
        : 0;

    $isComplete = $coverage['notRun'] === 0 && $coverage['failed'] === 0;
    $scope = $coverage['importName'] ?? 'All CSV imports';
@endphp

{{--
    The Results table counts images; this counts the searches behind them.

    Without it a run that stopped half way is indistinguishable from one that
    finished, because both end in the same confident "Showing 1 to N of N
    results" — the rows that were never searched simply are not there to miss.

    Built from Filament's own components. The admin panel loads only Filament's
    precompiled CSS (no app Vite/Tailwind build runs in deployment), so plain
    utility classes like `flex` or `p-4` are inert here; `fi-*` classes and the
    palette custom properties are the vocabulary that actually exists.
--}}
<x-filament::callout
    :color="$isComplete ? 'success' : 'warning'"
    :icon="$isComplete ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'"
    :heading="$scope . ' — ' . $coverage['searched'] . ' of ' . $coverage['total'] . ' searches run'"
>
    <x-slot name="description">
        {{ $coverage['withImages'] }} found images

        @if ($coverage['noImages'] > 0)
            &middot; {{ $coverage['noImages'] }} ran and found nothing
        @endif

        @if ($coverage['failed'] > 0)
            &middot; {{ $coverage['failed'] }} failed
        @endif

        @if ($coverage['notRun'] > 0)
            &middot; {{ $coverage['notRun'] }} not run yet.
            Rows that were never searched have no images to show, so this list is incomplete.
        @else
            .
        @endif

        @unless ($isComplete)
            <span
                role="progressbar"
                aria-valuenow="{{ $percent }}"
                aria-valuemin="0"
                aria-valuemax="100"
                style="display:block;height:.375rem;margin-top:.5rem;border-radius:9999px;background:rgba(128,128,128,.25);overflow:hidden"
            >
                <span style="display:block;height:100%;width:{{ $percent }}%;border-radius:9999px;background:var(--primary-600)"></span>
            </span>
        @endunless
    </x-slot>

    <x-slot name="controls">
        @if ($coverage['notRun'] > 0)
            <x-filament::button
                size="sm"
                color="primary"
                icon="heroicon-o-play"
                tag="a"
                :href="$coverage['notRunUrl']"
            >
                Run {{ $coverage['notRun'] }} not yet searched
            </x-filament::button>
        @endif

        @if ($coverage['noImages'] > 0)
            <x-filament::button
                size="sm"
                color="gray"
                tag="a"
                :href="$coverage['noImagesUrl']"
            >
                Review {{ $coverage['noImages'] }} empty
            </x-filament::button>
        @endif
    </x-slot>
</x-filament::callout>
