<?php

namespace GaNuongLaChanh\Sonic\Support;

class SearchTextNormalizer
{
    public static function normalize(string $text): string
    {
        // Convert all Unicode punctuation/symbol chars (including Chinese ones like “”：、《》)
        // into spaces, then normalize whitespace.
        $normalized = preg_replace('/[\p{P}\p{S}]+/u', ' ', $text);

        if (!is_string($normalized)) {
            return trim($text);
        }

        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', $normalized) ?? $normalized);
    }

    /**
     * Split query into normalized searchable terms.
     *
     * @return string[]
     */
    public static function extractTerms(string $text): array
    {
        $normalized = self::normalize($text);
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/[\s\p{Z}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return [$normalized];
        }

        return array_values(array_unique(array_filter(array_map('trim', $parts), function ($term) {
            return $term !== '';
        })));
    }
}
