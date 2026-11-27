<?php

namespace App\Support;

use Illuminate\Support\Str;

final class ProductVariantSku
{
    public static function generate(
        string $baseSku,
        ?string $size,
        ?string $color,
        string $emptySizeFallback = 'U'
    ): string {
        $sizePart = self::normalize($size);
        if ($sizePart === '') {
            $sizePart = $emptySizeFallback;
        }

        $parts = [$baseSku, $sizePart];
        $colorPart = self::normalize($color);
        if ($colorPart !== '') {
            $parts[] = $colorPart;
        }

        return implode('-', $parts);
    }

    private static function normalize(?string $value): string
    {
        $ascii = Str::ascii(trim($value ?? ''));

        return preg_replace('/[^A-Z0-9]/', '', strtoupper($ascii)) ?? '';
    }
}
