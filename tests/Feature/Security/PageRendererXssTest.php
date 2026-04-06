<?php

namespace Tests\Feature\Security;

use App\Services\PageRenderer;
use Tests\TestCase;

class PageRendererXssTest extends TestCase
{
    public function test_it_sanitizes_raw_html_content(): void
    {
        $renderer = new PageRenderer;
        $payload = '<script>alert("xss")</script><div onclick="alert(1)">Safe</div><p>Normal</p>';

        $result = $renderer->render($payload);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('onclick=', $result);
        $this->assertStringContainsString('<div>Safe</div>', $result);
        $this->assertStringContainsString('<p>Normal</p>', $result);
    }

    public function test_it_sanitizes_html_key_in_array_payload(): void
    {
        $renderer = new PageRenderer;
        $payload = [
            'html' => '<img src=x onerror=alert(1)><b>Bold</b><a href="javascript:alert(1)">Link</a>',
        ];

        $result = $renderer->render($payload);

        $this->assertStringContainsString('<img src="x"', $result);
        $this->assertStringNotContainsString('onerror=', $result);
        $this->assertStringContainsString('<b>Bold</b>', $result);
        $this->assertStringNotContainsString('javascript:alert(1)', $result);
    }
}
