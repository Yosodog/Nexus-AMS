<?php

namespace Tests\Unit\Services;

use App\Services\PageRenderer;
use Tests\UnitTestCase;

class PageRendererXssTest extends UnitTestCase
{
    public function test_normalize_html_strips_dangerous_tags(): void
    {
        $renderer = new PageRenderer;
        $payload = '<div>Safe</div><script>alert("xss")</script><object>data</object>';

        $result = $renderer->render($payload);

        $this->assertStringContainsString('<div>Safe</div>', $result);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('<object>', $result);
    }

    public function test_normalize_html_strips_event_handlers(): void
    {
        $renderer = new PageRenderer;
        $payload = '<img src="x" onerror="alert(1)"> <div onclick="evil()">Click</div>';

        $result = $renderer->render($payload);

        $this->assertStringNotContainsString('onerror', $result);
        $this->assertStringNotContainsString('onclick', $result);
    }

    public function test_normalize_html_strips_javascript_uris(): void
    {
        $renderer = new PageRenderer;
        $payload = '<a href="javascript:alert(1)">Click me</a> <iframe src="javascript:alert(2)"></iframe>';

        $result = $renderer->render($payload);

        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function test_normalize_html_preserves_allowed_tags_and_attributes(): void
    {
        $renderer = new PageRenderer;
        $payload = '<h1>Title</h1><p class="text-red-500">Paragraph</p><a href="https://google.com">Link</a>';

        $result = $renderer->render($payload);

        $this->assertStringContainsString('<h1>Title</h1>', $result);
        $this->assertStringContainsString('<p class="text-red-500">', $result);
        $this->assertStringContainsString('<a href="https://google.com">', $result);
    }
}
