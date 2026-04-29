<?php

namespace Tests\Unit\Services;

use App\Services\PageRenderer;
use Tests\TestCase;

class PageRendererXssTest extends TestCase
{
    public function test_normalize_html_sanitizes_dangerous_tags(): void
    {
        $renderer = new PageRenderer();
        $dangerousHtml = '<script>alert("xss")</script><div onmouseover="alert(1)">Hello</div><object>dangerous</object>';
        $result = $renderer->render($dangerousHtml);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('onmouseover', $result);
        $this->assertStringNotContainsString('<object>', $result);
        $this->assertStringContainsString('<div>Hello</div>', $result);
        // Script content should be stripped if it's inside the tag
        $this->assertStringNotContainsString('alert("xss")', $result);
    }

    public function test_normalize_html_unwraps_non_whitelisted_safe_tags(): void
    {
        $renderer = new PageRenderer();
        $html = '<font color="red"><span>Text</span></font>';
        $result = $renderer->render($html);

        $this->assertStringNotContainsString('<font', $result);
        $this->assertStringContainsString('<span>Text</span>', $result);
    }

    public function test_normalize_html_blocks_dangerous_protocols(): void
    {
        $renderer = new PageRenderer();
        $payloads = [
            '<a href="javascript:alert(1)">Click</a>' => '<a>Click</a>',
            '<a href="  javascript:alert(1)">Click</a>' => '<a>Click</a>',
            '<a href="java\0script:alert(1)">Click</a>' => '<a>Click</a>',
            '<img src="data:text/html,base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">' => '',
            '<iframe src="file:///etc/passwd"></iframe>' => '',
        ];

        foreach ($payloads as $input => $expected) {
            $result = $renderer->render($input);
            if ($expected === '') {
                $this->assertStringNotContainsString('src=', $result);
            } else {
                $this->assertStringContainsString($expected, $result);
                $this->assertStringNotContainsString('javascript:', $result);
            }
        }
    }

    public function test_normalize_html_handles_utf8_and_entities(): void
    {
        $renderer = new PageRenderer();
        $html = '<div>Héllò &amp; Welcome</div>';
        $result = $renderer->render($html);

        $this->assertStringContainsString('Héllò &amp; Welcome', $result);
    }

    public function test_normalize_html_handles_malformed_html_gracefully(): void
    {
        $renderer = new PageRenderer();
        $html = '<div>Unclosed div';
        $result = $renderer->render($html);

        $this->assertStringContainsString('<div>Unclosed div</div>', $result);
    }

    public function test_normalize_html_handles_multiple_root_nodes(): void
    {
        $renderer = new PageRenderer();
        $html = '<div>A</div><div>B</div>';
        $result = $renderer->render($html);

        $this->assertStringContainsString('<div>A</div><div>B</div>', $result);
    }
}
