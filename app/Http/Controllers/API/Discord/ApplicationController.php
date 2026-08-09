<?php

namespace App\Http\Controllers\API\Discord;

use App\Exceptions\ApplicationException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveDiscordActor;
use App\Http\Middleware\VerifyDiscordInteraction;
use App\Http\Requests\Discord\DiscordApplicationApproveRequest;
use App\Http\Requests\Discord\DiscordApplicationDenyRequest;
use App\Http\Requests\Discord\DiscordApplicationMessageRequest;
use App\Http\Requests\Discord\DiscordApplicationStoreRequest;
use App\Http\Requests\Discord\DiscordAttachChannelRequest;
use App\Models\Application;
use App\Models\DiscordAccount;
use App\Services\ApplicationService;
use App\Services\Discord\DiscordConnectionContext;
use Illuminate\Http\JsonResponse;

class ApplicationController extends Controller
{
    public function __construct(private readonly ApplicationService $applicationService) {}

    public function store(DiscordApplicationStoreRequest $request): JsonResponse
    {
        try {
            $application = $this->applicationService->createApplicationFromDiscord(
                $request->integer('nation_id'),
                $request->string('discord_user_id')->toString(),
                $request->string('discord_username')->toString(),
                $this->connection($request),
            );
            $nation = $this->applicationService->getNation($application->nation_id);
        } catch (ApplicationException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'application' => $application->toArray(),
            'nation' => $nation,
            'config' => $this->applicationService->getDiscordConfig(),
        ], 201);
    }

    public function attachChannel(DiscordAttachChannelRequest $request): JsonResponse
    {
        $application = Application::query()->findOrFail($request->integer('application_id'));

        try {
            $application = $this->applicationService->attachChannelToApplication(
                $application,
                $request->string('discord_channel_id')->toString()
            );
        } catch (ApplicationException $exception) {
            return $this->errorResponse($exception);
        }

        return response()->json([
            'application' => $application->toArray(),
        ]);
    }

    public function storeMessage(DiscordApplicationMessageRequest $request): JsonResponse
    {
        $message = $this->applicationService->logDiscordMessage($request->validated());

        if (! $message) {
            return response()->json(['logged' => false]);
        }

        return response()->json([
            'logged' => true,
            'message' => $message->toArray(),
        ]);
    }

    public function approve(DiscordApplicationApproveRequest $request): JsonResponse
    {
        $moderatorDiscordId = $this->authenticatedModeratorDiscordId($request);
        if ($moderatorDiscordId instanceof JsonResponse) {
            return $moderatorDiscordId;
        }

        try {
            $application = $this->applicationService->approveByDiscordUser(
                $request->string('applicant_discord_id')->toString(),
                $moderatorDiscordId,
                $request->string('approval_request_id')->toString(),
                $this->connection($request),
            );
        } catch (ApplicationException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'status' => 'approved',
            'application' => $application->toArray(),
            'config' => $this->applicationService->getDiscordConfig(),
        ]);
    }

    public function deny(DiscordApplicationDenyRequest $request): JsonResponse
    {
        $moderatorDiscordId = $this->authenticatedModeratorDiscordId($request);
        if ($moderatorDiscordId instanceof JsonResponse) {
            return $moderatorDiscordId;
        }

        try {
            $application = $this->applicationService->denyByDiscordUser(
                $request->string('applicant_discord_id')->toString(),
                $moderatorDiscordId,
                $request->string('denial_request_id')->toString(),
                $this->connection($request),
            );
        } catch (ApplicationException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'status' => 'denied',
            'application' => $application->toArray(),
            'config' => $this->applicationService->getDiscordConfig(),
        ]);
    }

    protected function errorResponse(ApplicationException $exception): JsonResponse
    {
        return response()->json([
            'error' => $exception->error,
            'message' => $exception->getMessage(),
            'context' => $exception->context,
        ], $exception->status);
    }

    private function authenticatedModeratorDiscordId(DiscordApplicationApproveRequest|DiscordApplicationDenyRequest $request): string|JsonResponse
    {
        $account = $request->attributes->get(ResolveDiscordActor::ACCOUNT_ATTRIBUTE);
        $claimedDiscordId = $request->string('moderator_discord_id')->toString();

        if (! $account instanceof DiscordAccount || ! hash_equals((string) $account->discord_id, $claimedDiscordId)) {
            return response()->json([
                'error' => 'discord_actor_mismatch',
                'message' => 'The moderator does not match the signed Discord interaction.',
                'context' => [],
            ], 403);
        }

        return (string) $account->discord_id;
    }

    private function connection(
        DiscordApplicationStoreRequest|DiscordApplicationApproveRequest|DiscordApplicationDenyRequest $request,
    ): ?DiscordConnectionContext {
        $connection = $request->attributes->get(VerifyDiscordInteraction::CONNECTION_ATTRIBUTE);

        return $connection instanceof DiscordConnectionContext ? $connection : null;
    }
}
