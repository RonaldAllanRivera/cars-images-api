<div class="flex flex-col items-center space-y-3">
    <img
        src="{{ $imageUrl }}"
        alt="{{ $title }}"
        loading="lazy"
        class="max-w-[400px] max-h-[400px] object-contain rounded-lg shadow-md bg-gray-900/60"
    >

    @if ($sourceUrl)
        <a
            href="{{ $sourceUrl }}"
            target="_blank"
            rel="noopener noreferrer"
            class="text-xs text-primary-400 hover:text-primary-300 underline break-all text-center"
        >
            {{ $sourceUrl }}
        </a>
    @endif

    @if ($title)
        <p class="text-xs text-gray-400 text-center">
            {{ $title }}
        </p>
    @endif
</div>
