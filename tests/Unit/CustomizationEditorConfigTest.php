<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CustomizationEditorConfigTest extends TestCase
{
    public function test_editor_does_not_enable_raw_html_or_source_editing_plugins(): void
    {
        $config = file_get_contents(dirname(__DIR__, 2).'/resources/js/ckeditor.js') ?: '';

        $this->assertStringNotContainsString('HtmlEmbed', $config);
        $this->assertStringNotContainsString('SourceEditing', $config);
        $this->assertStringNotContainsString('htmlEmbed', $config);
        $this->assertStringNotContainsString('sourceEditing', $config);
    }
}
