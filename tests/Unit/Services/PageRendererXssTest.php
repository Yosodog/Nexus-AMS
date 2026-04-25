<?php

namespace Tests\Unit\Services;

use App\Services\PageRenderer;
use Tests\UnitTestCase;

class PageRendererXssTest extends UnitTestCase
{
    public function test_normalize_html_does_not_sanitize_arbitrary_html(): void
    {
        $renderer = new PageRenderer;
        $payload = '<script>alert("xss")</script><div onclick="alert(1)">Click me</div>';

        $result = $renderer->render(['html' => $payload]);

        // This is expected to fail if the vulnerability exists
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('onclick', $result);
    }

    public function test_render_with_string_input_does_not_sanitize(): void
    {
        $renderer = new PageRenderer;
        $payload = '<img src=x onerror=alert(1)>';

        $result = $renderer->render($payload);

        $this->assertStringNotContainsString('onerror', $result);
    }

    public function test_normalize_html_handles_utf8(): void
    {
        $renderer = new PageRenderer;
        $payload = '<div>Héllö Wörld</div>';

        $result = $renderer->render(['html' => $payload]);

        $this->assertStringContainsString('H&eacute;ll&ouml; W&ouml;rld', $result);
    }

    public function test_normalize_html_prevents_entity_decoding_bypass(): void
    {
        $renderer = new PageRenderer;
        $payload = '&lt;script&gt;alert(1)&lt;/script&gt;';

        $result = $renderer->render(['html' => $payload]);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function test_normalize_html_strips_obfuscated_javascript_uri(): void
    {
        $renderer = new PageRenderer;
        $payload = '<a href="java script:alert(1)">Click</a>';

        $result = $renderer->render(['html' => $payload]);

        $this->assertStringNotContainsString('href', $result);
    }
}
