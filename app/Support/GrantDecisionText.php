<?php

namespace App\Support;

final class GrantDecisionText
{
    private const RESTRICTED_MEMBER_CONTENT = '/\b(?:fraud(?:ulent)?|security\s+(?:flag|signal|investigation)|risk\s+score|watchlist|blacklist|suspicious\s+activity|abuse\s+detection|anti[-\s]?abuse|internal\s+investigation)\b/iu';

    public static function sanitize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[ \t]+\n/u', "\n", $value) ?? '';
        $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? '';
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public static function containsRestrictedMemberContent(?string $value): bool
    {
        return $value !== null && preg_match(self::RESTRICTED_MEMBER_CONTENT, $value) === 1;
    }
}
