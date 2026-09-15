<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CustomizationEditorConfigTest extends TestCase
{
    public function test_editor_does_not_enable_raw_html_or_source_editing_plugins(): void
    {
        $config = file_get_contents(dirname(__DIR__, 2).'/resources/js/jodit.js') ?: '';
        $toolbarConfig = strstr($config, 'const customControls', true);

        $this->assertIsString($toolbarConfig);
        $this->assertStringNotContainsString("'source'", $toolbarConfig);
        $this->assertStringNotContainsString('sourceEditing', $config);
        $this->assertStringContainsString("'image', 'imageCaption', 'mediaEmbed', 'symbols'", $config);
        $this->assertStringContainsString("'table', 'hr', 'pageBreak'", $config);
    }
}
