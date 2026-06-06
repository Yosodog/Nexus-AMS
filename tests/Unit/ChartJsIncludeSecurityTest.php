<?php

namespace Tests\Unit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\UnitTestCase;

class ChartJsIncludeSecurityTest extends UnitTestCase
{
    public function test_views_do_not_include_unpinned_chart_js_cdn_scripts(): void
    {
        $basePath = dirname(__DIR__, 2);
        $offenders = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basePath.'/resources/views'));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname()) ?: '';

            if (str_contains($contents, 'https://cdn.jsdelivr.net/npm/chart.js"')) {
                $offenders[] = str_replace($basePath.'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_chart_js_component_pins_version_and_integrity(): void
    {
        $component = file_get_contents(dirname(__DIR__, 2).'/resources/views/components/chart-js.blade.php') ?: '';

        $this->assertStringContainsString('chart.js@4.5.1/dist/chart.umd.min.js', $component);
        $this->assertStringContainsString('integrity="sha384-', $component);
        $this->assertStringContainsString('crossorigin="anonymous"', $component);
    }
}
