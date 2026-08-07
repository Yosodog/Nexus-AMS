<?php

namespace Tests\Feature\Components;

use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;
use Tests\TestCase;

class ContextualHelpTest extends TestCase
{
    public function test_help_disclosure_explains_why_next_action_timing_support_and_owner(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-contextual-help title="Why this is blocked" owner="Finance policy" open>
                <x-slot:why>The account data is stale.</x-slot:why>
                <x-slot:next>Refresh the account before retrying.</x-slot:next>
                <x-slot:timing>The next successful synchronization updates this state.</x-slot:timing>
                <x-slot:support>Contact finance staff with the request ID.</x-slot:support>
            </x-contextual-help>
        BLADE);

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('open', $html);
        $this->assertStringContainsString('<summary', $html);
        $this->assertStringContainsString('Why this is blocked', $html);
        $this->assertStringContainsString('Why this is happening', $html);
        $this->assertStringContainsString('What to do next', $html);
        $this->assertStringContainsString('When it should change', $html);
        $this->assertStringContainsString('If you are still blocked', $html);
        $this->assertStringContainsString('Content owner: Finance policy', $html);
        $this->assertStringContainsString('role="note"', $html);
        $this->assertStringNotContainsString('onclick=', $html);
    }

    public function test_help_requires_maintained_owner_and_recovery_guidance(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('Contextual help requires a title and content owner.');

        Blade::render(<<<'BLADE'
            <x-contextual-help title="Blocked" owner="">
                <x-slot:why>Reason.</x-slot:why>
                <x-slot:next>Next step.</x-slot:next>
            </x-contextual-help>
        BLADE);
    }

    public function test_high_consequence_views_use_the_shared_guidance_contract(): void
    {
        $views = [
            'resources/views/grants/show_grant.blade.php',
            'resources/views/audit/index.blade.php',
            'resources/views/accounts/components/member_transfers.blade.php',
            'resources/views/user/settings.blade.php',
            'resources/views/admin/settings/partials/operations.blade.php',
            'resources/views/admin/applications/show.blade.php',
        ];

        foreach ($views as $view) {
            $this->assertStringContainsString(
                '<x-contextual-help',
                file_get_contents(base_path($view)),
                "{$view} should use the shared contextual-help component.",
            );
        }
    }
}
