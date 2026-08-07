<?php

namespace Tests\Feature\Components;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class FormRecoveryComponentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        View::share('errors', new ViewErrorBag);
    }

    public function test_field_anatomy_preserves_old_input_and_exposes_help_and_polite_status(): void
    {
        session()->put('_old_input', ['amount' => '275000']);
        request()->setLaravelSession(session()->driver());

        $html = Blade::render(<<<'BLADE'
            <x-form.input id="loan-amount" name="amount" label="Loan amount" type="number" value="100000" required>
                <x-slot:help>Enter at least $100,000.</x-slot:help>
                <x-slot:status>Checking the amount.</x-slot:status>
            </x-form.input>
        BLADE, ['errors' => new ViewErrorBag]);

        $this->assertStringContainsString('for="loan-amount"', $html);
        $this->assertStringContainsString('value="275000"', $html);
        $this->assertStringContainsString('required', $html);
        $this->assertStringContainsString('Required', $html);
        $this->assertStringContainsString('id="loan-amount-help"', $html);
        $this->assertStringContainsString('id="loan-amount-status"', $html);
        $this->assertStringContainsString('aria-describedby="loan-amount-help loan-amount-status"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
    }

    public function test_error_summary_links_to_the_invalid_control(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag([
            'account_id' => ['Select an account for the grant disbursement.'],
        ]));
        View::share('errors', $errors);

        $html = Blade::render(<<<'BLADE'
            <x-form.error-summary
                id="grant-errors"
                title="We could not submit the grant."
                :field-ids="['account_id' => 'grant-account']"
            />
            <x-form.select id="grant-account" name="account_id" label="Deposit account" required>
                <option value="">Choose an account</option>
            </x-form.select>
        BLADE, compact('errors'));

        $this->assertStringContainsString('id="grant-errors"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString('data-focus-on-load="true"', $html);
        $this->assertStringContainsString('href="#grant-account"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-errormessage="grant-account-error"', $html);
        $this->assertStringContainsString('id="grant-account-error"', $html);
    }

    public function test_auth_error_summary_uses_the_shared_link_contract(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag([
            'password' => ['The password is incorrect.'],
        ]));
        View::share('errors', $errors);

        $html = Blade::render(
            '<x-auth.error-summary id="login-errors" :field-ids="[\'password\' => \'login-password\']" />',
            compact('errors')
        );

        $this->assertStringContainsString('data-form-error-summary', $html);
        $this->assertStringContainsString('href="#login-password"', $html);
    }

    public function test_field_can_surface_a_domain_error_after_its_primary_validation_key(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag([
            'city_grant' => ['City grant data is temporarily unavailable.'],
        ]));
        View::share('errors', $errors);

        $html = Blade::render(<<<'BLADE'
            <x-form.select
                id="city-grant-account"
                name="account_id"
                label="Bank account"
                :error-keys="['account_id', 'city_grant']"
            >
                <option>Primary</option>
            </x-form.select>
        BLADE, compact('errors'));

        $this->assertStringContainsString('City grant data is temporarily unavailable.', $html);
        $this->assertStringContainsString('aria-errormessage="city-grant-account-error"', $html);
    }

    public function test_repeated_fields_generate_unique_ids_and_valid_label_targets(): void
    {
        $template = <<<'BLADE'
            <x-form.input name="reference" label="First reference" hint="First value." />
            <x-form.input name="reference" label="Second reference" hint="Second value." />
            <x-form.textarea name="notes" label="Notes" optional />
        BLADE;
        $html = Blade::render($template, ['errors' => new ViewErrorBag]);

        $document = new DOMDocument;
        $document->loadHTML('<!doctype html><html><body>'.$html.'</body></html>');
        $xpath = new DOMXPath($document);
        $ids = [];

        foreach ($xpath->query('//*[@id]') as $node) {
            $ids[] = $node->attributes->getNamedItem('id')->nodeValue;
        }

        $this->assertSame($ids, array_values(array_unique($ids)), 'Rendered form controls must not duplicate IDs.');

        foreach ($xpath->query('//label[@for]') as $label) {
            $targetId = $label->attributes->getNamedItem('for')->nodeValue;
            $this->assertSame(1, $xpath->query('//*[@id="'.$targetId.'"]')->length);
        }

        $secondRender = new DOMDocument;
        $secondRender->loadHTML(
            '<!doctype html><html><body>'.Blade::render($template, ['errors' => new ViewErrorBag]).'</body></html>'
        );
        $secondIds = [];

        foreach ((new DOMXPath($secondRender))->query('//input[@id] | //textarea[@id]') as $node) {
            $secondIds[] = $node->attributes->getNamedItem('id')->nodeValue;
        }

        $controlIds = [];
        foreach ($xpath->query('//input[@id] | //textarea[@id]') as $node) {
            $controlIds[] = $node->attributes->getNamedItem('id')->nodeValue;
        }

        $this->assertSame($controlIds, $secondIds, 'Generated IDs must remain stable across re-renders.');
        $this->assertStringContainsString('Optional', $html);
    }
}
