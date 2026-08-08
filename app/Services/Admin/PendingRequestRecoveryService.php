<?php

namespace App\Services\Admin;

use App\Enums\ApplicationStatus;
use App\Enums\GrantDecisionReason;
use App\Models\Application;
use App\Models\BlockadeReliefRequest;
use App\Models\CityGrantRequest;
use App\Models\DepositRequest;
use App\Models\GrantApplication;
use App\Models\Loan;
use App\Models\RebuildingRequest;
use App\Models\WarAidRequest;
use App\Services\PendingRequestsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PendingRequestRecoveryService
{
    public const DEFAULT_STALE_PENDING_HOURS = 24;

    public function __construct(private readonly PendingRequestsService $pendingRequests) {}

    /** @return array<int, string> */
    public function supportedTypes(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * @return array<int, array{
     *     type: string,
     *     label: string,
     *     totalPending: int,
     *     stalePending: int,
     *     oldestCreatedAt: ?Carbon
     * }>
     */
    public function summaries(int $olderThanHours = self::DEFAULT_STALE_PENDING_HOURS): array
    {
        $cutoff = now()->subHours($olderThanHours);

        return collect($this->definitions())
            ->map(function (array $definition, string $type) use ($cutoff): array {
                /** @var class-string<Model> $model */
                $model = $definition['model'];

                $baseQuery = $model::query()->where('status', $definition['pending_status']);
                $oldestCreatedAt = (clone $baseQuery)->oldest('created_at')->value('created_at');

                return [
                    'type' => $type,
                    'label' => $definition['label'],
                    'totalPending' => (clone $baseQuery)->count(),
                    'stalePending' => (clone $baseQuery)->where('created_at', '<=', $cutoff)->count(),
                    'oldestCreatedAt' => $oldestCreatedAt ? Carbon::parse($oldestCreatedAt) : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     type: string,
     *     label: string,
     *     olderThanHours: int,
     *     releasedCount: int,
     *     cutoff: Carbon
     * }
     */
    public function release(string $type, int $olderThanHours): array
    {
        $definition = $this->definitions()[$type];
        $cutoff = now()->subHours($olderThanHours);
        $releasedAt = now();

        $releasedCount = DB::transaction(fn (): int => DB::table($definition['table'])
            ->where('status', $definition['pending_status'])
            ->where('created_at', '<=', $cutoff)
            ->update(($definition['release_payload'])($releasedAt)));

        if ($releasedCount > 0) {
            $this->pendingRequests->flushCache();
        }

        return [
            'type' => $type,
            'label' => $definition['label'],
            'olderThanHours' => $olderThanHours,
            'releasedCount' => $releasedCount,
            'cutoff' => $cutoff,
        ];
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     table: string,
     *     model: class-string<Model>,
     *     pending_status: string,
     *     release_payload: \Closure(Carbon): array<string, mixed>
     * }>
     */
    private function definitions(): array
    {
        return [
            'war_aid' => [
                'label' => 'war aid requests',
                'table' => 'war_aid_requests',
                'model' => WarAidRequest::class,
                'pending_status' => 'pending',
                'release_payload' => fn (Carbon $releasedAt): array => [
                    'status' => 'denied',
                    'pending_key' => null,
                    'denied_at' => $releasedAt,
                ],
            ],
            'applications' => [
                'label' => 'applications',
                'table' => 'applications',
                'model' => Application::class,
                'pending_status' => ApplicationStatus::Pending->value,
                'release_payload' => fn (Carbon $releasedAt): array => [
                    'status' => ApplicationStatus::Cancelled->value,
                    'pending_key' => null,
                    'cancelled_at' => $releasedAt,
                ],
            ],
            'loans' => [
                'label' => 'loan applications',
                'table' => 'loans',
                'model' => Loan::class,
                'pending_status' => 'pending',
                'release_payload' => fn (Carbon $releasedAt): array => [
                    'status' => 'denied',
                    'pending_key' => null,
                ],
            ],
            'deposit_requests' => [
                'label' => 'deposit requests',
                'table' => 'deposit_requests',
                'model' => DepositRequest::class,
                'pending_status' => 'pending',
                'release_payload' => fn (Carbon $releasedAt): array => [
                    'status' => 'expired',
                    'pending_key' => null,
                ],
            ],
            'grant_applications' => [
                'label' => 'grant applications',
                'table' => 'grant_applications',
                'model' => GrantApplication::class,
                'pending_status' => 'pending',
                'release_payload' => fn (Carbon $releasedAt): array => [
                    'status' => 'denied',
                    'pending_key' => null,
                    'denied_at' => $releasedAt,
                    'decided_at' => $releasedAt,
                    'decision_reason_code' => GrantDecisionReason::OtherPolicyReason->value,
                    'decision_explanation' => 'This request was closed during stale-request recovery. Contact leadership if the original request still needs review.',
                ],
            ],
            'city_grant_requests' => [
                'label' => 'city grant requests',
                'table' => 'city_grant_requests',
                'model' => CityGrantRequest::class,
                'pending_status' => 'pending',
                'release_payload' => fn (Carbon $releasedAt): array => [
                    'status' => 'denied',
                    'pending_key' => null,
                    'denied_at' => $releasedAt,
                ],
            ],
            'rebuilding_requests' => [
                'label' => 'rebuilding requests',
                'table' => 'rebuilding_requests',
                'model' => RebuildingRequest::class,
                'pending_status' => 'pending',
                'release_payload' => fn (Carbon $releasedAt): array => [
                    'status' => 'expired',
                    'pending_key' => null,
                    'expired_at' => $releasedAt,
                ],
            ],
            'blockade_relief_pending' => [
                'label' => 'pending blockade relief requests',
                'table' => 'blockade_relief_requests',
                'model' => BlockadeReliefRequest::class,
                'pending_status' => 'pending',
                'release_payload' => fn (Carbon $releasedAt): array => [
                    'status' => 'expired',
                    'pending_key' => null,
                    'expired_at' => $releasedAt,
                    'resolution_reason' => 'admin_stale_release',
                ],
            ],
            'blockade_relief_claimed' => [
                'label' => 'claimed blockade relief requests',
                'table' => 'blockade_relief_requests',
                'model' => BlockadeReliefRequest::class,
                'pending_status' => 'claimed',
                'release_payload' => fn (Carbon $releasedAt): array => [
                    'status' => 'expired',
                    'pending_key' => null,
                    'expired_at' => $releasedAt,
                    'resolution_reason' => 'admin_stale_release',
                ],
            ],
        ];
    }
}
