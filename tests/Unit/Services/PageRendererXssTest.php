<?php

namespace Tests\Unit\Services;

use App\Services\PageRenderer;
use Tests\TestCase;

class PageRendererXssTest extends TestCase
{
    public function test_normalize_html_sanitizes_dangerous_tags(): void
    {
        $renderer = new PageRenderer;
        $dangerousHtml = '<script>alert("xss")</script><div onmouseover="alert(1)">Hello</div>';
        $result = $renderer->render($dangerousHtml);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('onmouseover', $result);
    }
}
