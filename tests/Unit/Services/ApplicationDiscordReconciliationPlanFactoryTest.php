<?php

namespace Tests\Unit\Services;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Services\Discord\ApplicationDiscordReconciliationPlanFactory;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ApplicationDiscordReconciliationPlanFactoryTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = '123456789012345678';

    private const GUILD_ID = '223456789012345678';

    private const USER_ID = '323456789012345678';

    private const APPLICANT_ROLE_ID = '423456789012345678';

    private const INTERVIEWER_ROLE_ID = '523456789012345678';

    private const MEMBER_ROLE_ID = '623456789012345678';

    private const CATEGORY_ID = '723456789012345678';

    private const ANNOUNCEMENT_CHANNEL_ID = '823456789012345678';

    private const CONNECTION_ID = '123e4567-e89b-42d3-a456-426614174000';

    private ApplicationDiscordReconciliationPlanFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = app(ApplicationDiscordReconciliationPlanFactory::class);
        SettingService::setApplicationsDiscordApplicantRoleId(self::APPLICANT_ROLE_ID);
        SettingService::setApplicationsDiscordIaRoleId(self::INTERVIEWER_ROLE_ID);
        SettingService::setApplicationsDiscordMemberRoleId(self::MEMBER_ROLE_ID);
        SettingService::setApplicationsDiscordInterviewCategoryId(self::CATEGORY_ID);
        SettingService::setApplicationsApprovalAnnouncementChannelId(self::ANNOUNCEMENT_CHANNEL_ID);
        SettingService::setApplicationsApprovalMessageTemplate('A new member was approved in Nexus.');
    }

    public function test_it_builds_the_exact_pending_reconciliation_contract(): void
    {
        $application = $this->application(ApplicationStatus::Pending);

        $plan = $this->plan($application, revision: 3);

        $this->assertSame([], $plan['issues']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plan['desired_hash']);
        $this->assertSame([
            'contract_version' => 1,
            'installation' => [
                'application_id' => self::APP_ID,
                'guild_id' => self::GUILD_ID,
                'connection_id' => self::CONNECTION_ID,
                'generation' => 7,
            ],
            'application' => [
                'id' => $application->id,
                'state' => 'pending',
                'discord_user_id' => self::USER_ID,
                'nation_id' => 9001,
                'revision' => 3,
            ],
            'desired' => [
                'channel' => [
                    'mode' => 'ensure',
                    'category_id' => self::CATEGORY_ID,
                    'name' => "app-{$application->id}-9001-example-leader",
                    'topic' => "nexus-application:{$application->id};nation:9001 | https://politicsandwar.com/nation/id=9001",
                    'staff_role_ids' => [self::INTERVIEWER_ROLE_ID],
                    'intro_messages' => [[
                        'key' => 'application.submitted',
                        'content' => "Application #{$application->id} for nation #9001 is ready for interview. Continue in this private channel; staff will respond here.",
                    ]],
                ],
                'roles' => [
                    'add' => [self::APPLICANT_ROLE_ID],
                    'remove' => [],
                ],
                'notifications' => [],
            ],
        ], $plan['payload']);
    }

    public function test_it_builds_approved_finalization_without_recalculating_discord_policy(): void
    {
        $application = $this->application(ApplicationStatus::Approved, [
            'discord_channel_id' => '923456789012345678',
        ]);

        $plan = $this->plan($application);

        $this->assertSame('absent', $plan['payload']['desired']['channel']['mode']);
        $this->assertSame('923456789012345678', $plan['payload']['desired']['channel']['channel_id']);
        $this->assertSame([self::MEMBER_ROLE_ID], $plan['payload']['desired']['roles']['add']);
        $this->assertSame([self::APPLICANT_ROLE_ID], $plan['payload']['desired']['roles']['remove']);
        $this->assertSame([[
            'key' => 'application.approved',
            'destination' => [
                'type' => 'channel',
                'channel_id' => self::ANNOUNCEMENT_CHANNEL_ID,
            ],
            'content' => 'A new member was approved in Nexus.',
        ]], $plan['payload']['desired']['notifications']);
    }

    public function test_approval_announcements_allow_raw_discord_channel_mentions(): void
    {
        SettingService::setApplicationsApprovalMessageTemplate(
            'Welcome! Read <#423456789012345678> and visit <#523456789012345678>.',
        );

        $plan = $this->plan($this->application(ApplicationStatus::Approved));

        $this->assertSame([], $plan['issues']);
        $this->assertSame(
            'Welcome! Read <#423456789012345678> and visit <#523456789012345678>.',
            $plan['payload']['desired']['notifications'][0]['content'],
        );
    }

    public function test_terminal_denial_and_cancellation_never_grant_the_member_role(): void
    {
        foreach ([ApplicationStatus::Denied, ApplicationStatus::Cancelled] as $status) {
            $plan = $this->plan($this->application($status));

            $this->assertSame('absent', $plan['payload']['desired']['channel']['mode']);
            $this->assertSame([], $plan['payload']['desired']['roles']['add']);
            $this->assertSame([self::APPLICANT_ROLE_ID], $plan['payload']['desired']['roles']['remove']);
            $this->assertSame([], $plan['payload']['desired']['notifications']);
        }
    }

    public function test_missing_interviewer_configuration_never_creates_a_staff_inaccessible_channel(): void
    {
        SettingService::setApplicationsDiscordIaRoleId('invalid');

        $plan = $this->plan($this->application(ApplicationStatus::Pending));

        $this->assertSame('unchanged', $plan['payload']['desired']['channel']['mode']);
        $this->assertSame([], $plan['payload']['desired']['channel']['staff_role_ids']);
        $this->assertSame([], $plan['payload']['desired']['channel']['intro_messages']);
        $this->assertContains('interviewer_role_not_configured', $plan['issues']);
    }

    public function test_invalid_optional_configuration_is_omitted_and_reported(): void
    {
        SettingService::setApplicationsDiscordApplicantRoleId('invalid');
        SettingService::setApplicationsDiscordMemberRoleId('invalid');
        SettingService::setApplicationsApprovalAnnouncementChannelId('invalid');
        SettingService::setApplicationsApprovalMessageTemplate('@everyone assignment ready');
        $application = $this->application(ApplicationStatus::Approved, [
            'discord_channel_id' => 'invalid',
        ]);

        $plan = $this->plan($application);

        $this->assertArrayNotHasKey('channel_id', $plan['payload']['desired']['channel']);
        $this->assertSame([], $plan['payload']['desired']['roles']['add']);
        $this->assertSame([], $plan['payload']['desired']['roles']['remove']);
        $this->assertSame([], $plan['payload']['desired']['notifications']);
        $this->assertEqualsCanonicalizing([
            'application_channel_invalid',
            'applicant_role_not_configured',
            'member_role_not_configured',
            'approval_announcement_channel_invalid',
            'approval_announcement_template_invalid',
        ], $plan['issues']);
    }

    public function test_repair_can_suppress_one_time_intro_and_announcement_messages(): void
    {
        $pending = $this->plan(
            $this->application(ApplicationStatus::Pending),
            includeOneTimeMessages: false,
        );
        $approved = $this->plan(
            $this->application(ApplicationStatus::Approved),
            includeOneTimeMessages: false,
        );

        $this->assertSame([], $pending['payload']['desired']['channel']['intro_messages']);
        $this->assertSame([], $approved['payload']['desired']['notifications']);
    }

    public function test_desired_hash_ignores_transport_generation_and_reconciliation_revision(): void
    {
        $application = $this->application(ApplicationStatus::Pending);
        $first = $this->plan($application, generation: 2, revision: 3);
        $second = $this->plan($application, generation: 99, revision: 44);

        $this->assertSame($first['desired_hash'], $second['desired_hash']);

        SettingService::setApplicationsDiscordApplicantRoleId('923456789012345679');
        $changed = $this->plan($application, generation: 99, revision: 44);
        $this->assertNotSame($first['desired_hash'], $changed['desired_hash']);
    }

    public function test_it_rejects_invalid_authoritative_identifiers(): void
    {
        $application = $this->application(ApplicationStatus::Pending);

        $this->expectException(InvalidArgumentException::class);
        $this->factory->make($application, 'invalid', self::GUILD_ID, self::CONNECTION_ID, 1, 1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function application(ApplicationStatus $status, array $overrides = []): Application
    {
        return Application::query()->create(array_merge([
            'nation_id' => 9001,
            'leader_name_snapshot' => 'Example Leader',
            'discord_user_id' => self::USER_ID,
            'discord_username' => 'example-user',
            'status' => $status,
            'pending_key' => $status === ApplicationStatus::Pending ? 1 : null,
        ], $overrides));
    }

    /** @return array{payload: array<string, mixed>, issues: list<string>, desired_hash: string} */
    private function plan(
        Application $application,
        int $generation = 7,
        int $revision = 2,
        bool $includeOneTimeMessages = true,
    ): array {
        return $this->factory->make(
            $application,
            self::APP_ID,
            self::GUILD_ID,
            self::CONNECTION_ID,
            $generation,
            $revision,
            $includeOneTimeMessages,
        );
    }
}
