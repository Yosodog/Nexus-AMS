<?php

namespace App\Support;

final class PWBBCode
{
    public static function escapeText(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        return str_replace(['[', ']'], ['［', '］'], $text);
    }
}
