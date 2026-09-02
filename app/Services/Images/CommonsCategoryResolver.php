<?php

namespace App\Services\Images;

class CommonsCategoryResolver
{
    /**
     * Tokens the EPA vehicle CSV carries that Commons category names never do.
     * Drivetrain, body style and powertrain qualifiers: the category is
     * "Ford F150", never "Ford F150 Pickup 2WD FFV".
     *
     * @var array<int, string>
     */
    private const QUALIFIERS = [
        'AWD', '4WD', '2WD', 'FWD', 'RWD', 'xDrive', 'sDrive', 'quattro', '4MATIC',
        'FFV', 'MHEV', 'PHEV', 'EcoDiesel', 'LWB', 'SWB', 'Pickup', 'Truck', 'Van',
        'Wagon', 'Convertible', 'Cabriolet', 'Roadster', 'Coupe', 'Sedan',
        'Hatchback', 'Hardtop', 'Gran Turismo', 'Gran Coupe', 'New',
    ];

    /**
     * Characters MediaWiki refuses to put in a page title.
     *
     * These must be filtered out rather than merely tolerated. Asked about a
     * title containing one, the API answers `{"invalid": true}` with NO
     * "missing" key — so a probe that only tests for `missing` reads it as an
     * existing category. 27 distinct models in the EPA CSV carry ">" in a GVWR
     * clause ("F150 2.7L 2WD GVWR>6649 LBS"), and every one of them would
     * otherwise resolve to a category that cannot exist.
     */
    private const ILLEGAL_TITLE_CHARS = '/[#<>\[\]|{}]/';

    public function __construct(
        protected ModelSearchTermNormalizer $normalizer = new ModelSearchTermNormalizer,
    ) {}

    /**
     * Candidate Commons category names, most specific first.
     *
     * The caller probes them in order and takes the first that exists, so the
     * ordering is the whole contract: a shorter name is a broader category,
     * and reaching it first would attach the wrong photographs to a search.
     *
     * @return array<int, string>
     */
    public function candidates(string $make, string $model): array
    {
        $base = $this->collapse(
            preg_replace('/\([^)]*\)/', ' ', $this->normalizer->normalize($model))
        );
        $stripped = $this->stripQualifiers($base);

        $names = [];

        // BOTH token lists are shrunk. Shrinking only the qualifier-stripped
        // one made a real category unreachable whenever the model string began
        // with a qualifier: "New Beetle Convertible" reduces to the single
        // token "Beetle", so the loop never ran and the only names on offer
        // were the full string and "Volkswagen Beetle" — the 1938 Type 1. A
        // New Beetle search stored classic Beetles, cached forever.
        foreach ([$base, $stripped] as $source) {
            $this->push($names, $source);

            $tokens = $source === '' ? [] : explode(' ', $source);

            // Never shrink to nothing: one model token must remain, so the
            // bare make is never probed. Category:Mitsubishi is the whole
            // marque and would answer a search for one specific truck with
            // arbitrary Mitsubishis.
            for ($length = count($tokens) - 1; $length >= 1; $length--) {
                $this->push($names, implode(' ', array_slice($tokens, 0, $length)));
            }
        }

        // Most specific first. The caller takes the first name that exists, so
        // interleaving two shrink sequences without re-sorting could offer a
        // one-token name before a three-token one and attach the wrong car.
        // PHP 8 sorts are stable, so equal-length names keep insertion order.
        usort(
            $names,
            static fn (string $a, string $b): int => substr_count($b, ' ') <=> substr_count($a, ' '),
        );

        $titles = array_map(static fn (string $name): string => trim($make).' '.$name, $names);

        // Dropping an unusable candidate is not merely tidy: it lets the walk
        // continue to the shortened name that does exist. "F150 2.7L 2WD
        // GVWR>6649 LBS" falls through to "Ford F150".
        return array_values(array_filter(
            $titles,
            static fn (string $title): bool => preg_match(self::ILLEGAL_TITLE_CHARS, $title) !== 1,
        ));
    }

    /**
     * @param  array<int, string>  $names
     */
    private function push(array &$names, string $candidate): void
    {
        $candidate = trim($this->collapse($candidate), ' -');

        if ($candidate !== '' && ! in_array($candidate, $names, true)) {
            $names[] = $candidate;
        }
    }

    private function stripQualifiers(string $value): string
    {
        $alternatives = implode('|', array_map(
            static fn (string $qualifier): string => preg_quote($qualifier, '/'),
            self::QUALIFIERS,
        ));

        $value = preg_replace('/\b(?:'.$alternatives.')\b/iu', ' ', $value);
        $value = preg_replace('/\b\d+\s*(?:door|inch\s+Wheels)\b/iu', ' ', $value);

        return $this->collapse($value);
    }

    private function collapse(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value));
    }
}
