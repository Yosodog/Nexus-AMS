<?php

namespace App\Services;

use App\Models\IntelReport;
use App\Models\Nation;

class IntelReportService
{
    public function store(array $data, string $source = 'web', ?int $userId = null): IntelReport
    {
        $nation = Nation::whereRaw('LOWER(nation_name) = ?', [strtolower($data['nation_name'])])->first();
        $hash = $this->fingerprint($data['nation_name'], $data['raw_text'] ?? '');

        $existing = IntelReport::where('hash', $hash)->first();

        if ($existing) {
            $existing->touch();

            return $existing;
        }

        return IntelReport::create([
            'nation_id' => $nation?->id,
            'nation_name' => $data['nation_name'],
            'user_id' => $userId,
            'money' => $data['money'] ?? 0,
            'coal' => $data['coal'] ?? 0,
            'oil' => $data['oil'] ?? 0,
            'uranium' => $data['uranium'] ?? 0,
            'lead' => $data['lead'] ?? 0,
            'iron' => $data['iron'] ?? 0,
            'bauxite' => $data['bauxite'] ?? 0,
            'gasoline' => $data['gasoline'] ?? 0,
            'munitions' => $data['munitions'] ?? 0,
            'steel' => $data['steel'] ?? 0,
            'aluminum' => $data['aluminum'] ?? 0,
            'food' => $data['food'] ?? 0,
            'operation_cost' => $data['operation_cost'] ?? 0,
            'spies_captured' => $data['spies_captured'] ?? 0,
            'was_detected' => $data['was_detected'] ?? false,
            'source' => $source,
            'raw_text' => $data['raw_text'] ?? '',
            'hash' => $hash,
        ]);
    }

    protected function fingerprint(string $nationName, string $rawText): string
    {
        $normalized = preg_replace('/\\s+/', ' ', trim($rawText));

        return hash('sha256', strtolower($nationName).'|'.($normalized ?? ''));
    }
}
