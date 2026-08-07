<?php

namespace Tests\Feature\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class LazyImageComponentTest extends TestCase
{
    public function test_lazy_image_reserves_space_and_exposes_a_stable_fallback(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-media.lazy-image
                src="https://example.test/flag.png"
                alt="Flag of Test Nation"
                :width="48"
                :height="32"
                fallback="TN"
                class="h-8 w-12"
            />
        BLADE);

        $this->assertStringContainsString('data-lazy-image', $html);
        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringContainsString('aria-label="Flag of Test Nation"', $html);
        $this->assertStringContainsString('width="48"', $html);
        $this->assertStringContainsString('height="32"', $html);
        $this->assertStringContainsString('style="aspect-ratio: 48 / 32;"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('decoding="async"', $html);
        $this->assertStringContainsString('x-on:error="$el.hidden = true"', $html);
        $this->assertStringContainsString('data-lazy-image-fallback', $html);
        $this->assertStringContainsString('TN', $html);
    }

    public function test_lazy_image_keeps_its_fallback_when_no_source_is_available(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-media.lazy-image
                :src="null"
                alt="Nation flag unavailable"
                :width="48"
                :height="32"
                fallback="?"
            />
        BLADE);

        $this->assertStringContainsString('aria-label="Nation flag unavailable"', $html);
        $this->assertStringContainsString('data-lazy-image-fallback', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_identity_media_can_remain_eager_without_losing_dimensions_or_fallback(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-media.lazy-image
                src="https://example.test/identity.png"
                alt="Member identity"
                :width="96"
                :height="64"
                loading="eager"
                fallback="MI"
            />
        BLADE);

        $this->assertStringContainsString('loading="eager"', $html);
        $this->assertStringContainsString('width="96"', $html);
        $this->assertStringContainsString('height="64"', $html);
        $this->assertStringContainsString('MI', $html);
    }
}
