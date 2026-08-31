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

        foreach ([$base, $stripped] as $candidate) {
            $this->push($names, $candidate);
        }

        $tokens = $stripped === '' ? [] : explode(' ', $stripped);

        // Shrink the token prefix, but never to nothing: one model token must
        // remain, so the bare make is never probed. Category:Mitsubishi is the
        // whole brand, and would answer a search for one specific truck with
        // arbitrary Mitsubishis.
        for ($length = count($tokens) - 1; $length >= 1; $length--) {
            $this->push($names, implode(' ', array_slice($tokens, 0, $length)));
        }

        return array_map(static fn (string $name): string => trim($make).' '.$name, $names);
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
