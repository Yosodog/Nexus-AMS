<?php

namespace App\Http\Controllers\API\Discord;

use App\Enums\ApplicationStatus;
use App\Exceptions\ApplicationException;
use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\CityGrantRequest;
use App\Models\GrantApplication;
use App\Models\Loan;
use App\Models\MemberTransfer;
use App\Models\RebuildingRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WarAidRequest;
use App\Services\ApplicationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    use DiscordApiResponses;

    public function requests(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'type' => ['nullable', Rule::in(['grant', 'city_grant', 'loan', 'war_aid', 'rebuilding', 'withdrawal', 'member_transfer', 'application'])],
            'status' => ['nullable', Rule::in(['open', 'closed', 'needs-attention'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $limit = (int) ($data['limit'] ?? 50);
        $items = collect();

        foreach ($this->permittedSources($actor) as $type => $source) {
            if (isset($data['type']) && $data['type'] !== $type) {
                continue;
            }
            [$query, $statusColumn, $deepLink] = $source;
            if (isset($data['status'])) {
                $this->applyQueueStatus($query, $type, $data['status']);
            } elseif ($type === 'withdrawal') {
                $query->where('is_pending', true);
            } else {
                $query->where($statusColumn, $type === 'application' ? ApplicationStatus::Pending->value : 'pending');
            }

            $items->push(...$query->latest()->limit($limit)->get()->map(fn ($model): array => [
                'type' => $type,
                'id' => $model->id,
                'status' => $type === 'withdrawal'
                    ? ($model->is_pending ? 'pending' : 'complete')
                    : ($model->{$statusColumn} instanceof \BackedEnum ? $model->{$statusColumn}->value : $model->{$statusColumn}),
                'nation_id' => $model->nation_id ?? $model->from_nation_id ?? null,
                'created_at' => optional($model->created_at)->toIso8601String(),
                'deep_link_path' => $deepLink,
            ]));
        }

        if ($items->isEmpty() && $this->permittedSources($actor) === []) {
            return $this->discordError('forbidden', 'This actor has no staff request permissions.', 403);
        }

        return $this->discordData($items->sortByDesc('created_at')->take($limit)->values()->all());
    }

    public function applications(Request $request): JsonResponse
    {
        $this->authorizeApplications($request);
        $data = $request->validate([
            'status' => ['nullable', Rule::in(ApplicationStatus::values())],
            'filter' => ['nullable', Rule::in(['pending', 'stale'])],
            'query' => ['nullable', 'string', 'max:100'],
            'applicant_discord_id' => ['nullable', 'string', 'regex:/^\d{1,20}$/'],
            'discord_channel_id' => ['nullable', 'string', 'regex:/^\d{1,20}$/'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $applications = Application::query()
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(! isset($data['status']), fn ($query) => $query->where('status', ApplicationStatus::Pending->value))
            ->when(($data['filter'] ?? null) === 'stale', fn ($query) => $query->where('created_at', '<=', now()->subDays(7)))
            ->when(isset($data['applicant_discord_id']), fn ($query) => $query->where('discord_user_id', $data['applicant_discord_id']))
            ->when(isset($data['discord_channel_id']), fn ($query) => $query->where('discord_channel_id', $data['discord_channel_id']))
            ->when(isset($data['query']), function ($query) use ($data): void {
                $term = '%'.addcslashes($data['query'], '%_\\').'%';
                $query->where(fn ($nested) => $nested
                    ->where('leader_name_snapshot', 'like', $term)
                    ->orWhere('discord_username', 'like', $term)
                    ->orWhere('nation_id', $data['query']));
            })
            ->latest()
            ->limit((int) ($data['limit'] ?? 50))
            ->get();

        return $this->discordData($applications->map(fn (Application $application): array => $this->applicationPayload($application, false))->all());
    }

    public function application(Request $request, Application $application): JsonResponse
    {
        $this->authorizeApplications($request);
        $application->load(['messages' => fn ($query) => $query->oldest()]);

        return $this->discordData($this->applicationPayload($application, true));
    }

    public function approveApplication(Request $request, Application $application, ApplicationService $service): JsonResponse
    {
        $actor = $this->authorizeApplications($request);
        try {
            $application = $service->approveById(
                $application,
                $actor,
                (string) $request->header('X-Discord-User-ID'),
                (string) $request->header('X-Discord-Interaction-ID'),
            );
        } catch (ApplicationException $exception) {
            return $this->discordError($exception->error, $exception->getMessage(), $exception->status, $exception->context);
        }

        return $this->discordData($this->applicationPayload($application, false));
    }

    public function denyApplication(Request $request, Application $application, ApplicationService $service): JsonResponse
    {
        $actor = $this->authorizeApplications($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $application = $service->denyById(
                $application,
                $actor,
                (string) $request->header('X-Discord-User-ID'),
                $data['reason'],
                (string) $request->header('X-Discord-Interaction-ID'),
            );
        } catch (ApplicationException $exception) {
            return $this->discordError($exception->error, $exception->getMessage(), $exception->status, $exception->context);
        }

        return $this->discordData($this->applicationPayload($application, false));
    }

    /** @return array<string, array{0:Builder,1:string,2:string}> */
    private function permittedSources(User $actor): array
    {
        $sources = [];
        if ($actor->hasPermission('manage-grants')) {
            $sources['grant'] = [GrantApplication::query(), 'status', '/admin/grants'];
            $sources['city_grant'] = [CityGrantRequest::query(), 'status', '/admin/grants/cities'];
        }
        if ($actor->hasPermission('manage-loans')) {
            $sources['loan'] = [Loan::query(), 'status', '/admin/loans'];
        }
        if ($actor->hasPermission('view-war-aid')) {
            $sources['war_aid'] = [WarAidRequest::query(), 'status', '/admin/defense/war-aid'];
        }
        if ($actor->hasPermission('view-rebuilding')) {
            $sources['rebuilding'] = [RebuildingRequest::query(), 'status', '/admin/defense/rebuilding'];
        }
        if ($actor->hasPermission('manage-accounts')) {
            $sources['withdrawal'] = [Transaction::query()->where('transaction_type', 'withdrawal'), 'is_pending', '/admin/withdrawals'];
            $sources['member_transfer'] = [MemberTransfer::query(), 'status', '/admin/accounts/member-transfers'];
        }
        if ($actor->hasPermission('manage-applications')) {
            $sources['application'] = [Application::query(), 'status', '/admin/applications'];
        }

        return $sources;
    }

    private function applicationPayload(Application $application, bool $withMessages): array
    {
        return [
            'id' => $application->id,
            'nation_id' => $application->nation_id,
            'leader_name' => $application->leader_name_snapshot,
            'discord_user_id' => $application->discord_user_id,
            'discord_username' => $application->discord_username,
            'status' => $application->status->value,
            'discord_channel_id' => $application->discord_channel_id,
            'denial_reason' => $application->denial_reason,
            'created_at' => $application->created_at->toIso8601String(),
            'deep_link_path' => '/admin/applications/'.$application->id,
            'messages' => $withMessages ? $application->messages->map(fn ($message): array => [
                'id' => $message->id,
                'author' => $message->discord_username,
                'content' => $message->content,
                'is_staff' => (bool) $message->is_staff,
                'sent_at' => optional($message->sent_at)->toIso8601String(),
            ])->all() : null,
        ];
    }

    private function applyQueueStatus(Builder $query, string $type, string $status): void
    {
        if ($type === 'withdrawal') {
            match ($status) {
                'open' => $query->where('is_pending', true),
                'closed' => $query->where('is_pending', false),
                'needs-attention' => $query->where(fn ($nested) => $nested
                    ->where('requires_admin_approval', true)
                    ->orWhere('bank_attempt_status', Transaction::BANK_ATTEMPT_NEEDS_RECONCILIATION)),
            };

            return;
        }

        $pending = $type === 'application' ? ApplicationStatus::Pending->value : 'pending';
        match ($status) {
            'open' => $query->where('status', $pending),
            'closed' => $query->where('status', '!=', $pending),
            'needs-attention' => $type === 'loan'
                ? $query->whereIn('status', ['missed', 'past_due'])
                : $query->where('status', $pending),
        };
    }

    private function authorizeApplications(Request $request): User
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('manage-applications'), 403);

        return $actor;
    }

    private function actor(Request $request): User
    {
        $actor = $request->attributes->get('discord_actor');
        abort_unless($actor instanceof User, 401, 'Discord actor context is missing.');

        return $actor;
    }
}
