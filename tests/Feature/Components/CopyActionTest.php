<?php

namespace Tests\Feature\Components;

use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;
use Tests\TestCase;

class CopyActionTest extends TestCase
{
    public function test_it_renders_an_explicit_visible_canonical_value_with_accessible_feedback(): void
    {
        $html = Blade::render(
            '<x-copy-action value="REQ-0042" label="request ID" class="custom-copy-action" />'
        );

        $this->assertStringContainsString('data-copy-action', $html);
        $this->assertStringContainsString('data-copy-value="REQ-0042"', $html);
        $this->assertStringContainsString('data-copy-readable', $html);
        $this->assertStringContainsString('>REQ-0042</code>', $html);
        $this->assertStringContainsString('Request ID:', $html);
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('aria-label="Copy request ID: REQ-0042"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('aria-atomic="true"', $html);
        $this->assertStringContainsString('custom-copy-action', $html);
        $this->assertMatchesRegularExpression(
            '/aria-describedby="([^"]+)".*id="\1"/s',
            $html
        );
    }

    public function test_it_keeps_the_canonical_value_separate_from_labels_and_escapes_it(): void
    {
        $html = Blade::render(
            '<x-copy-action :value="$identifier" label="support ID" />',
            ['identifier' => 'REQ-"<&>']
        );

        $this->assertStringContainsString('data-copy-value="REQ-&quot;&lt;&amp;&gt;"', $html);
        $this->assertStringContainsString('>REQ-&quot;&lt;&amp;&gt;</code>', $html);
        $this->assertStringNotContainsString('data-copy-value="Support ID:', $html);
        $this->assertStringNotContainsString('type="hidden"', $html);
    }

    public function test_repeated_actions_have_unique_live_region_ids(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-copy-action value="REQ-0042" label="request ID" />
            <x-copy-action value="BANK-0084" label="bank correlation ID" />
        BLADE);

        preg_match_all('/id="(copy-action-status-[^"]+)"/', $html, $matches);

        $this->assertCount(2, $matches[1]);
        $this->assertCount(2, array_unique($matches[1]));

        foreach ($matches[1] as $statusId) {
            $this->assertStringContainsString('aria-describedby="'.$statusId.'"', $html);
        }
    }

    public function test_resource_vectors_use_a_fixed_key_order_and_decimal_format(): void
    {
        $html = Blade::render(
            '<x-copy-resource-vector :resources="$resources" />',
            ['resources' => ['steel' => 2.5, 'money' => 1000, 'food' => 3]]
        );

        $this->assertStringContainsString(
            'money=1000.00;coal=0.00;oil=0.00;uranium=0.00;iron=0.00;bauxite=0.00;lead=0.00;gasoline=0.00;munitions=0.00;steel=2.50;aluminum=0.00;food=3.00',
            html_entity_decode($html)
        );
        $this->assertStringContainsString('aria-label="Copy resource vector:', $html);
    }

    public function test_it_rejects_a_missing_canonical_value(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('Copy actions require an explicit, non-empty canonical value.');

        Blade::render('<x-copy-action label="request ID" />');
    }
}
