<?php

namespace App\Http\Controllers\API\Discord;

use App\Exceptions\ApplicationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Discord\DiscordApplicationApproveRequest;
use App\Http\Requests\Discord\DiscordApplicationDenyRequest;
use App\Http\Requests\Discord\DiscordApplicationMessageRequest;
use App\Http\Requests\Discord\DiscordApplicationStoreRequest;
use App\Http\Requests\Discord\DiscordAttachChannelRequest;
use App\Models\Application;
use App\Services\ApplicationService;
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
                $request->string('discord_username')->toString()
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

        $application = $this->applicationService->attachChannelToApplication(
            $application,
            $request->string('discord_channel_id')->toString()
        );

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
        try {
            $application = $this->applicationService->approveByDiscordUser(
                $request->string('applicant_discord_id')->toString(),
                $request->string('moderator_discord_id')->toString()
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
        try {
            $application = $this->applicationService->denyByDiscordUser(
                $request->string('applicant_discord_id')->toString(),
                $request->string('moderator_discord_id')->toString()
            );
        } catch (ApplicationException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'status' => 'denied',
            'application' => $application->toArray(),
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
}
