<?php

namespace Tests\Feature\Components;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class AccessibleFormComponentsTest extends TestCase
{
    public function test_input_connects_its_label_and_help_text(): void
    {
        $html = Blade::render(
            '<x-form.input id="profile-email" name="email" label="Email address" hint="Used for account notices." />',
            ['errors' => new ViewErrorBag]
        );

        $this->assertStringContainsString('for="profile-email"', $html);
        $this->assertStringContainsString('id="profile-email"', $html);
        $this->assertStringContainsString('id="profile-email-help"', $html);
        $this->assertStringContainsString('aria-describedby="profile-email-help"', $html);
    }

    public function test_select_connects_validation_errors_and_marks_the_control_invalid(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag([
            'account_id' => ['Choose an available account.'],
        ]));
        View::share('errors', $errors);

        $html = Blade::render(
            '<x-form.select id="grant-account" name="account_id" label="Bank account"><option>Primary</option></x-form.select>',
            compact('errors')
        );

        $this->assertStringContainsString('for="grant-account"', $html);
        $this->assertStringContainsString('aria-describedby="grant-account-error"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('id="grant-account-error"', $html);
        $this->assertStringContainsString('role="alert"', $html);
    }

    public function test_repeated_controls_can_receive_unique_contextual_ids(): void
    {
        $html = Blade::render(
            <<<'BLADE'
                <x-form.toggle id="nation-email-delivery" name="channels[]" label="Nation alerts by email" />
                <x-form.toggle id="market-email-delivery" name="channels[]" label="Market alerts by email" />
            BLADE,
            ['errors' => new ViewErrorBag]
        );

        $this->assertSame(1, substr_count($html, 'id="nation-email-delivery"'));
        $this->assertSame(1, substr_count($html, 'id="market-email-delivery"'));
        $this->assertStringContainsString('for="nation-email-delivery"', $html);
        $this->assertStringContainsString('for="market-email-delivery"', $html);
    }

    public function test_icon_button_renders_an_accessible_link_with_a_tooltip(): void
    {
        $html = Blade::render(
            '<x-icon-button href="/next" label="Next page"><svg></svg></x-icon-button>',
            ['errors' => new ViewErrorBag]
        );

        $this->assertStringContainsString('href="/next"', $html);
        $this->assertStringContainsString('aria-label="Next page"', $html);
        $this->assertStringContainsString('data-tip="Next page"', $html);
        $this->assertStringContainsString('nexus-icon-button', $html);
    }

    public function test_loading_icon_button_is_disabled_and_announces_busy_state(): void
    {
        $html = Blade::render(
            '<x-icon-button label="Refresh data" loading><svg></svg></x-icon-button>',
            ['errors' => new ViewErrorBag]
        );

        $this->assertStringContainsString('aria-busy="true"', $html);
        $this->assertStringContainsString('aria-disabled="true"', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('nexus-icon-button__loading', $html);
    }

    public function test_async_state_uses_a_polite_live_region_and_exposes_retry(): void
    {
        $html = Blade::render(
            '<x-async.state state="temporary_failure" title="Could not refresh" message="Try again safely." retry />',
            ['errors' => new ViewErrorBag]
        );

        $this->assertStringContainsString('data-async-state="temporary_failure"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('data-async-retry', $html);
        $this->assertStringContainsString('Try again safely.', $html);
    }

    public function test_async_button_has_distinct_label_and_spinner_hooks(): void
    {
        $html = Blade::render(
            '<x-async.button type="submit" busy-label="Saving…">Save changes</x-async.button>',
            ['errors' => new ViewErrorBag]
        );

        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('data-async-busy-label="Saving…"', $html);
        $this->assertStringContainsString('data-async-button-spinner', $html);
        $this->assertStringContainsString('data-async-button-label', $html);
        $this->assertStringContainsString('Save changes', $html);
    }

    public function test_global_async_status_supports_offline_and_session_expiry_without_focus_changes(): void
    {
        $html = Blade::render(
            '<x-async.global-status />',
            ['errors' => new ViewErrorBag]
        );

        $this->assertStringContainsString('data-async-global-state="offline"', $html);
        $this->assertStringContainsString('data-async-global-state="session_expired"', $html);
        $this->assertStringContainsString('data-async-live-region', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringNotContainsString('autofocus', $html);
    }
}
