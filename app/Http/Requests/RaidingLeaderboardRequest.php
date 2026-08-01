<?php

namespace App\Http\Requests;

use App\Services\LeaderboardDirectoryService;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RaidingLeaderboardRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if (! $this->isRaidLeaderboardRequest()) {
            return [];
        }

        return [
            'from' => ['nullable', Rule::date()->format('Y-m-d')],
            'to' => ['nullable', Rule::date()->format('Y-m-d')],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->isRaidLeaderboardRequest()
                || $validator->errors()->has('from')
                || $validator->errors()->has('to')) {
                return;
            }

            $from = $this->filled('from')
                ? Carbon::parse($this->string('from')->toString())->startOfDay()
                : now()->subDays(30)->startOfDay();
            $to = $this->filled('to')
                ? Carbon::parse($this->string('to')->toString())->endOfDay()
                : now()->endOfDay();

            if ($from->greaterThan($to)) {
                $validator->errors()->add('from', 'The start date must be before or equal to the end date.');

                return;
            }

            if ($from->diffInDays($to) > LeaderboardDirectoryService::MAX_RAID_WINDOW_DAYS) {
                $validator->errors()->add(
                    'from',
                    sprintf('Raid leaderboard ranges may not exceed %d days.', LeaderboardDirectoryService::MAX_RAID_WINDOW_DAYS)
                );
            }
        }];
    }

    private function isRaidLeaderboardRequest(): bool
    {
        return $this->routeIs('defense.raid-leaderboard')
            || $this->route('board') === 'raid-performance';
    }
}
