<?php

namespace Tests\Unit\Services;

use App\Services\PageRenderer;
use Tests\UnitTestCase;

class PageRendererXssTest extends UnitTestCase
{
    public function test_normalize_html_strips_dangerous_tags(): void
    {
        $renderer = new PageRenderer;

        $payload = '<script>alert("xss")</script><p>Safe</p><object data="evil.swf"></object>';
        $output = $renderer->render($payload);

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringNotContainsString('<object>', $output);
        $this->assertStringContainsString('<p>Safe</p>', $output);
    }

    public function test_normalize_html_strips_event_handlers(): void
    {
        $renderer = new PageRenderer;

        $payload = '<p onmouseover="alert(1)">Hover me</p><img src="x" onerror="alert(1)">';
        $output = $renderer->render($payload);

        $this->assertStringNotContainsString('onmouseover', $output);
        $this->assertStringNotContainsString('onerror', $output);
        $this->assertStringContainsString('<p>Hover me</p>', $output);
    }

    public function test_normalize_html_strips_javascript_urls(): void
    {
        $renderer = new PageRenderer;

        $payload = '<a href="javascript:alert(1)">Click me</a><img src="javascript:alert(1)">';
        $output = $renderer->render($payload);

        $this->assertStringNotContainsString('javascript:', $output);
    }

    public function test_normalize_html_strips_other_dangerous_protocols(): void
    {
        $renderer = new PageRenderer;

        $payload = '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">XSS</a><a href="vbscript:msgbox(1)">VBS</a>';
        $output = $renderer->render($payload);

        $this->assertStringNotContainsString('data:', $output);
        $this->assertStringNotContainsString('vbscript:', $output);
    }
}
