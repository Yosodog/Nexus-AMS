<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class GameInfoQueryService
{
    /**
     * @return array{game_date: string, global: float, north_america: float, south_america: float, europe: float, africa: float, asia: float, australia: float, antarctica: float}
     *
     * @throws ConnectionException
     * @throws PWQueryFailedException
     */
    public function getEconomySnapshot(): array
    {
        $builder = (new GraphQLQueryBuilder)
            ->setRootField('game_info')
            ->addFields(['game_date'])
            ->addNestedField('radiation', function (GraphQLQueryBuilder $builder) {
                $builder->addFields([
                    'global',
                    'north_america',
                    'south_america',
                    'europe',
                    'africa',
                    'asia',
                    'australia',
                    'antarctica',
                ]);
            });

        $response = (new QueryService)->sendQuery($builder, headers: false, handlePagination: false);
        $rawGameDate = trim((string) ($response->game_date ?? ''));
        $gameDate = $this->normalizeGameDate($rawGameDate);
        $radiation = (array) ($response->radiation ?? []);

        if ($gameDate === null) {
            throw new RuntimeException('Game information response omitted a valid game date.');
        }

        $fields = [
            'global',
            'north_america',
            'south_america',
            'europe',
            'africa',
            'asia',
            'australia',
            'antarctica',
        ];

        foreach ($fields as $field) {
            if (
                ! array_key_exists($field, $radiation)
                || ! is_numeric($radiation[$field])
                || ! is_finite((float) $radiation[$field])
                || (float) $radiation[$field] < 0
            ) {
                throw new RuntimeException("Game information response omitted valid {$field} radiation.");
            }
        }

        return [
            'game_date' => $gameDate,
            'global' => (float) $radiation['global'],
            'north_america' => (float) $radiation['north_america'],
            'south_america' => (float) $radiation['south_america'],
            'europe' => (float) $radiation['europe'],
            'africa' => (float) $radiation['africa'],
            'asia' => (float) $radiation['asia'],
            'australia' => (float) $radiation['australia'],
            'antarctica' => (float) $radiation['antarctica'],
        ];
    }

    private function normalizeGameDate(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $dateParts) === 1) {
            return checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1])
                ? $value
                : null;
        }

        if (preg_match(
            '/^(?<date>(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2}))T\d{2}:\d{2}:\d{2}(?<fraction>\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',
            $value,
            $dateParts,
        ) !== 1) {
            return null;
        }

        if (! checkdate((int) $dateParts['month'], (int) $dateParts['day'], (int) $dateParts['year'])) {
            return null;
        }

        $normalizedTimestamp = str_ends_with($value, 'Z')
            ? substr($value, 0, -1).'+00:00'
            : $value;
        $format = ($dateParts['fraction'] ?? '') === ''
            ? '!Y-m-d\TH:i:sP'
            : '!Y-m-d\TH:i:s.uP';
        $parsedTimestamp = DateTimeImmutable::createFromFormat($format, $normalizedTimestamp);
        $parseErrors = DateTimeImmutable::getLastErrors();

        if (
            $parsedTimestamp === false
            || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))
        ) {
            return null;
        }

        return $dateParts['date'];
    }
}
