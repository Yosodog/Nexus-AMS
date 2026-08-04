<?php

namespace App\Http\Controllers\API\Milcom;

use App\Http\Controllers\Controller;
use App\Http\Requests\Milcom\UpdateSettingsRequest;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $settings = $request->validated();

        DB::transaction(function () use ($settings): void {
            SettingService::setDiscordWarRoomForumId((string) ($settings['forum_id'] ?? ''));
            SettingService::setDiscordWarRoomDefenseRoleId((string) ($settings['defense_role_id'] ?? ''));
            SettingService::setValue(
                'milcom_forum_tag_ids',
                json_encode($settings['forum_tag_ids'] ?? [], JSON_THROW_ON_ERROR)
            );
            SettingService::setValue(
                'milcom_counter_monitoring_enabled',
                $settings['counter_monitoring_enabled'] ? '1' : '0'
            );
            SettingService::setValue('milcom_default_war_type', $settings['default_war_type']);
            SettingService::setValue('milcom_default_war_reason', $settings['default_war_reason']);
        }, attempts: 5);

        return response()->json([
            'data' => ['settings' => $settings],
            'meta' => ['updated_at' => now()->toIso8601String()],
            'links' => [],
            'message' => 'Milcom settings saved.',
        ]);
    }
}
