<?php

namespace App\Services;

use InvalidArgumentException;

class IntelReportParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $text): array
    {
        $normalized = $this->normalize($text);

        return array_merge(
            [
                'nation_name' => $this->extractNationName($normalized),
            ],
            $this->extractResources($normalized),
            [
                'operation_cost' => $this->extractOperationCost($normalized),
                'spies_captured' => $this->extractSpiesCaptured($normalized),
                'was_detected' => $this->wasDetected($normalized),
                'raw_text' => $text,
            ]
        );
    }

    protected function normalize(string $text): string
    {
        $collapsed = preg_replace('/\\s+/', ' ', trim($text));

        return $collapsed ?? '';
    }

    protected function extractNationName(string $text): string
    {
        $patterns = [
            '/gathered intelligence about\\s+(.+?)[\\.!]/i',
            '/gathered intelligence about\\s+(.+?)\\s+Your spies/i',
            '/gathered intelligence about\\s+(.+?)\\s+has\\s*\\$/i',
            '/about\\s+(.+?)\\s+has\\s*\\$/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return trim($matches[1]);
            }
        }

        throw new InvalidArgumentException('Could not find the nation name in the intel report.');
    }

    /**
     * @return array<string, float>
     */
    protected function extractResources(string $text): array
    {
        $pattern = '/has\\s*\\$([\\d,\\.]+),\\s*([\\d,\\.]+)\\s*coal,\\s*([\\d,\\.]+)\\s*oil,\\s*([\\d,\\.]+)\\s*uranium,\\s*([\\d,\\.]+)\\s*lead,\\s*([\\d,\\.]+)\\s*iron,\\s*([\\d,\\.]+)\\s*bauxite,\\s*([\\d,\\.]+)\\s*gasoline,\\s*([\\d,\\.]+)\\s*munitions,\\s*([\\d,\\.]+)\\s*steel,\\s*([\\d,\\.]+)\\s*aluminum\\s*and\\s*([\\d,\\.]+)\\s*food/i';

        if (! preg_match($pattern, $text, $matches)) {
            throw new InvalidArgumentException('Could not parse resource amounts from the intel report.');
        }

        return [
            'money' => $this->toDecimal($matches[1]),
            'coal' => $this->toDecimal($matches[2]),
            'oil' => $this->toDecimal($matches[3]),
            'uranium' => $this->toDecimal($matches[4]),
            'lead' => $this->toDecimal($matches[5]),
            'iron' => $this->toDecimal($matches[6]),
            'bauxite' => $this->toDecimal($matches[7]),
            'gasoline' => $this->toDecimal($matches[8]),
            'munitions' => $this->toDecimal($matches[9]),
            'steel' => $this->toDecimal($matches[10]),
            'aluminum' => $this->toDecimal($matches[11]),
            'food' => $this->toDecimal($matches[12]),
        ];
    }

    protected function extractOperationCost(string $text): float
    {
        if (preg_match('/operation cost you \\$([\\d,\\.]+)/i', $text, $matches)) {
            return $this->toDecimal($matches[1]);
        }

        return 0;
    }

    protected function extractSpiesCaptured(string $text): int
    {
        if (preg_match('/(\\d+) of your spies were captured/i', $text, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    protected function wasDetected(string $text): bool
    {
        if (stripos($text, 'undetected') !== false) {
            return false;
        }

        if (preg_match('/were detected|were caught|spies were captured/i', $text)) {
            return true;
        }

        return $this->extractSpiesCaptured($text) > 0;
    }

    protected function toDecimal(string $value): float
    {
        return (float) str_replace([',', '$'], '', $value);
    }
}
