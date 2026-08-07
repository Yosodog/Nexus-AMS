<?php

namespace Tests\Feature\Components;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ChartEquivalentTest extends TestCase
{
    public function test_shared_chart_loader_registers_accessible_table_export_and_series_distinctions(): void
    {
        $html = Blade::render('<x-chart-js />');

        $this->assertStringContainsString('nexusAccessibleEquivalent', $html);
        $this->assertStringContainsString('dataset.chartEquivalent', $html);
        $this->assertStringContainsString('Download chart data CSV', $html);
        $this->assertStringContainsString('View data table', $html);
        $this->assertStringContainsString('maxInlineRows = 50', $html);
        $this->assertStringContainsString('/^\\s*[=+\\-@]/', $html);
        $this->assertStringContainsString('seriesLinePatterns', $html);
        $this->assertStringContainsString('seriesPointStyles', $html);
        $this->assertStringContainsString('aria-describedby', $html);
        $this->assertStringContainsString('integrity="sha384-', $html);
    }

    public function test_every_chart_view_loads_the_shared_equivalent_plugin(): void
    {
        $chartViews = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($file): bool => str_contains($file->getContents(), 'new Chart('));

        $this->assertNotEmpty($chartViews);

        foreach ($chartViews as $view) {
            $this->assertStringContainsString(
                '<x-chart-js',
                $view->getContents(),
                $view->getRelativePathname().' must load the shared chart accessibility plugin.',
            );
        }
    }
}
