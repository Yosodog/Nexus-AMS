<?php

namespace Tests\Feature\Components;

use App\Domain\Milcom\Enums\OperationStatus;
use App\Enums\ApplicationStatus;
use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class NexusStatusTest extends TestCase
{
    #[DataProvider('semanticIntentProvider')]
    public function test_it_renders_every_semantic_intent_with_text_and_an_icon(string $intent, string $icon): void
    {
        $html = Blade::render(
            '<x-nexus-status :label="$label" :intent="$intent" :icon="$icon" class="custom-status" />',
            ['label' => 'Readable status', 'intent' => $intent, 'icon' => $icon]
        );

        $this->assertStringContainsString('Readable status', $html);
        $this->assertStringContainsString('nexus-status--semantic', $html);
        $this->assertStringContainsString('nexus-status--'.$intent, $html);
        $this->assertStringContainsString('data-status-intent="'.$intent.'"', $html);
        $this->assertStringContainsString('data-status-icon="'.$icon.'"', $html);
        $this->assertStringContainsString('nexus-status__icon', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('custom-status', $html);
    }

    #[DataProvider('supportedIconProvider')]
    public function test_it_renders_every_supported_icon_token(string $icon): void
    {
        $html = Blade::render(
            '<x-nexus-status label="Status" intent="neutral" :icon="$icon" />',
            ['icon' => $icon]
        );

        $this->assertStringContainsString('data-status-icon="'.$icon.'"', $html);
        $this->assertStringContainsString('nexus-status__icon', $html);
    }

    public function test_it_renders_an_optional_explanation_without_pointer_only_interaction(): void
    {
        $html = Blade::render(
            '<x-nexus-status label="Pending" intent="pending" icon="clock" explanation="Awaiting staff review." />'
        );

        $this->assertStringContainsString('nexus-status--explained', $html);
        $this->assertStringContainsString('nexus-status__explanation', $html);
        $this->assertStringContainsString('Awaiting staff review.', $html);
        $this->assertStringNotContainsString('title=', $html);
    }

    public function test_it_escapes_status_text(): void
    {
        $html = Blade::render(
            '<x-nexus-status :label="$label" intent="failure" icon="x-circle" :explanation="$explanation" />',
            [
                'label' => '<script>alert(1)</script>',
                'explanation' => '<img src=x onerror=alert(1)>',
            ]
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    #[DataProvider('invalidContractProvider')]
    public function test_it_rejects_invalid_component_contracts(
        mixed $label,
        mixed $intent,
        mixed $icon,
        mixed $explanation,
        string $expectedMessage,
    ): void {
        try {
            Blade::render(
                '<x-nexus-status :label="$label" :intent="$intent" :icon="$icon" :explanation="$explanation" />',
                compact('label', 'intent', 'icon', 'explanation')
            );

            $this->fail('Expected the status component contract to reject invalid input.');
        } catch (Throwable $throwable) {
            $invalidArgument = $this->findInvalidArgument($throwable);

            $this->assertInstanceOf(InvalidArgumentException::class, $invalidArgument);
            $this->assertSame($expectedMessage, $invalidArgument->getMessage());
        }
    }

    public function test_application_status_presentation_mapping_is_exhaustive_and_renderable(): void
    {
        $expected = [
            ApplicationStatus::Pending->value => [
                'label' => 'Pending',
                'intent' => 'pending',
                'icon' => 'clock',
                'explanation' => 'Awaiting staff review.',
            ],
            ApplicationStatus::Approved->value => [
                'label' => 'Approved',
                'intent' => 'success',
                'icon' => 'check-circle',
                'explanation' => 'Approved for onboarding.',
            ],
            ApplicationStatus::Denied->value => [
                'label' => 'Denied',
                'intent' => 'failure',
                'icon' => 'x-circle',
                'explanation' => 'The application was not approved.',
            ],
            ApplicationStatus::Cancelled->value => [
                'label' => 'Cancelled',
                'intent' => 'neutral',
                'icon' => 'minus-circle',
                'explanation' => 'Closed without a decision.',
            ],
        ];

        $this->assertSame(array_keys($expected), ApplicationStatus::values());

        foreach (ApplicationStatus::cases() as $status) {
            $this->assertSame($expected[$status->value], $status->presentation());
            $this->assertPresentationRenders($status->presentation());
        }
    }

    public function test_milcom_operation_status_presentation_mapping_is_exhaustive_and_renderable(): void
    {
        $expected = [
            OperationStatus::Draft->value => [
                'label' => 'Draft',
                'intent' => 'neutral',
                'icon' => 'pencil-square',
                'explanation' => 'Setup is still in progress.',
            ],
            OperationStatus::Generating->value => [
                'label' => 'Building teams',
                'intent' => 'pending',
                'icon' => 'arrow-path',
                'explanation' => 'Milcom is building recommendations.',
            ],
            OperationStatus::Review->value => [
                'label' => 'Ready for review',
                'intent' => 'pending',
                'icon' => 'eye',
                'explanation' => 'Recommendations are ready for staff review.',
            ],
            OperationStatus::Dispatching->value => [
                'label' => 'Creating Discord rooms',
                'intent' => 'active',
                'icon' => 'paper-airplane',
                'explanation' => 'Milcom is creating the operation rooms.',
            ],
            OperationStatus::Active->value => [
                'label' => 'Active',
                'intent' => 'active',
                'icon' => 'bolt',
                'explanation' => 'Assignments are live.',
            ],
            OperationStatus::Completed->value => [
                'label' => 'Completed',
                'intent' => 'success',
                'icon' => 'check-circle',
                'explanation' => 'The operation has finished.',
            ],
            OperationStatus::Archived->value => [
                'label' => 'Archived',
                'intent' => 'neutral',
                'icon' => 'archive-box',
                'explanation' => 'Retained for reference.',
            ],
            OperationStatus::Failed->value => [
                'label' => 'Failed',
                'intent' => 'failure',
                'icon' => 'x-circle',
                'explanation' => 'The operation could not be completed.',
            ],
        ];

        $this->assertSame(array_keys($expected), array_column(OperationStatus::cases(), 'value'));

        foreach (OperationStatus::cases() as $status) {
            $this->assertSame($expected[$status->value], $status->presentation());
            $this->assertPresentationRenders($status->presentation());
        }
    }

    public function test_styles_include_new_semantic_intents_and_legacy_aliases(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);

        foreach (['neutral', 'pending', 'active', 'success', 'warning', 'failure'] as $intent) {
            $this->assertStringContainsString('.nexus-status--'.$intent, $css);
        }

        foreach (['error', 'info'] as $legacyAlias) {
            $this->assertStringContainsString('.nexus-status--'.$legacyAlias, $css);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function semanticIntentProvider(): array
    {
        return [
            'neutral' => ['neutral', 'minus-circle'],
            'pending' => ['pending', 'clock'],
            'active' => ['active', 'bolt'],
            'success' => ['success', 'check-circle'],
            'warning' => ['warning', 'exclamation-triangle'],
            'failure' => ['failure', 'x-circle'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function supportedIconProvider(): array
    {
        return [
            'archive box' => ['archive-box'],
            'arrow path' => ['arrow-path'],
            'bolt' => ['bolt'],
            'check circle' => ['check-circle'],
            'clock' => ['clock'],
            'eye' => ['eye'],
            'warning triangle' => ['exclamation-triangle'],
            'lock' => ['lock-closed'],
            'minus circle' => ['minus-circle'],
            'paper airplane' => ['paper-airplane'],
            'pencil square' => ['pencil-square'],
            'x circle' => ['x-circle'],
        ];
    }

    /**
     * @return array<string, array{mixed, mixed, mixed, mixed, string}>
     */
    public static function invalidContractProvider(): array
    {
        return [
            'non-string label' => [42, 'neutral', 'minus-circle', null, 'The status label must be a string.'],
            'blank label' => ['   ', 'neutral', 'minus-circle', null, 'The status label is required.'],
            'unknown intent' => ['Status', 'danger', 'minus-circle', null, 'Unsupported status intent [danger].'],
            'unknown icon' => ['Status', 'neutral', 'skull', null, 'Unsupported status icon [skull].'],
            'non-string explanation' => ['Status', 'neutral', 'minus-circle', [], 'The status explanation must be a string or null.'],
        ];
    }

    /**
     * @param  array{label: string, intent: string, icon: string, explanation: string}  $presentation
     */
    private function assertPresentationRenders(array $presentation): void
    {
        $html = Blade::render(
            <<<'BLADE'
                <x-nexus-status
                    :label="$presentation['label']"
                    :intent="$presentation['intent']"
                    :icon="$presentation['icon']"
                    :explanation="$presentation['explanation']"
                />
            BLADE,
            compact('presentation')
        );

        $this->assertStringContainsString($presentation['label'], $html);
        $this->assertStringContainsString($presentation['explanation'], $html);
        $this->assertStringContainsString('data-status-intent="'.$presentation['intent'].'"', $html);
        $this->assertStringContainsString('data-status-icon="'.$presentation['icon'].'"', $html);
    }

    private function findInvalidArgument(Throwable $throwable): ?InvalidArgumentException
    {
        do {
            if ($throwable instanceof InvalidArgumentException) {
                return $throwable;
            }

            $throwable = $throwable->getPrevious();
        } while ($throwable !== null);

        return null;
    }
}
