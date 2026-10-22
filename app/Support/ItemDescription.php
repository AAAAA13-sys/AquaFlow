<?php

namespace App\Support;

final class ItemDescription
{
    public static function fromLines(iterable $lines, ?string $fallback = null): string
    {
        $names = [];
        foreach ($lines as $line) {
            $names[] = $line->quantity.' × '.$line->item_name;
        }
        if ($names !== []) {
            return implode(', ', $names);
        }

        $text = trim($fallback ?? '');
        if ($text === '' || $text === '0 gal') {
            return '—';
        }
        $catalog = ['s' => 'Slim 5-Gal Refill', 'slim' => 'Slim 5-Gal Refill', 'r' => 'Round 5-Gal Refill', 'round' => 'Round 5-Gal Refill', 'news' => 'New Slim 5-Gal Jug', 'newr' => 'New Round 5-Gal Jug'];
        $parts = preg_split('/\s*\+\s*|,\s*/u', $text);
        $changed = false;
        foreach ($parts as &$part) {
            if (preg_match('/^(\d+)\s*(?:[x×]\s*)?(newS|newR|slim|round|S|R)$/iu', trim($part), $match)) {
                $part = $match[1].' × '.$catalog[strtolower($match[2])];
                $changed = true;
            }
        }

        return $changed ? implode(', ', $parts) : $text;
    }
}
