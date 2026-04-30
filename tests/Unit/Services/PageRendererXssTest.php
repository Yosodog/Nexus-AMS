<?php

namespace Tests\Unit\Services;

use App\Services\PageRenderer;
use Tests\UnitTestCase;

class PageRendererXssTest extends UnitTestCase
{
    public function test_normalize_html_strips_dangerous_tags(): void
    {
        $renderer = new PageRenderer;
        $badHtml = '<script>alert("xss")</script><p>Hello</p><iframe src="javascript:alert(1)"></iframe>';
        $safeHtml = $renderer->render($badHtml);

        $this->assertStringNotContainsString('<script>', $safeHtml);
        $this->assertStringNotContainsString('src="javascript:alert(1)"', $safeHtml);
        $this->assertStringContainsString('<p>Hello</p>', $safeHtml);
    }

    public function test_normalize_html_strips_event_handlers(): void
    {
        $renderer = new PageRenderer;
        $badHtml = '<button onclick="alert(1)">Click me</button><img src="x" onerror="alert(1)">';
        $safeHtml = $renderer->render($badHtml);

        $this->assertStringNotContainsString('onclick', $safeHtml);
        $this->assertStringNotContainsString('onerror', $safeHtml);
    }

    public function test_normalize_html_strips_javascript_uris(): void
    {
        $renderer = new PageRenderer;
        $badHtml = '<a href="javascript:alert(1)">Link</a><img src="java&#10;script:alert(1)">';
        $safeHtml = $renderer->render($badHtml);

        $this->assertStringNotContainsString('javascript:', $safeHtml);
        $this->assertStringNotContainsString('java&#10;script:', $safeHtml);
    }
}
