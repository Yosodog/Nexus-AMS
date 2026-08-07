<?php

namespace App\Services\Settings;

class PublicSiteSettings
{
    public function __construct(private readonly SettingValueStore $settings) {}

    public function getFaviconPath(): ?string
    {
        $value = $this->settings->get('favicon_path');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setFaviconPath(?string $path): void
    {
        $this->settings->set('favicon_path', $path ?? '');
    }

    public function getHomepageHeadline(string $allianceName): string
    {
        $default = "Build your next chapter with {$allianceName}";

        return $this->settings->getStringWithoutPersisting('home_headline', $default);
    }

    public function setHomepageHeadline(string $headline): void
    {
        $this->settings->set('home_headline', $headline);
    }

    public function getHomepageTagline(string $allianceName): string
    {
        $default = "{$allianceName} is where ambitious nations find real support, sharp coordination, and a community worth staying for.";

        return $this->settings->getStringWithoutPersisting('home_tagline', $default);
    }

    public function setHomepageTagline(string $tagline): void
    {
        $this->settings->set('home_tagline', $tagline);
    }

    public function getHomepageAbout(string $allianceName): string
    {
        $default = "{$allianceName} is for members who want a steady alliance, active leadership, and a community that works well together.";

        return $this->settings->getStringWithoutPersisting('home_about', $default);
    }

    public function setHomepageAbout(string $about): void
    {
        $this->settings->set('home_about', $about);
    }

    /**
     * @return array<int, string>
     */
    public function getHomepageHighlights(): array
    {
        $raw = $this->settings->get('home_highlights');

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                return collect($decoded)
                    ->map(fn ($item) => is_string($item) ? trim($item) : '')
                    ->filter()
                    ->values()
                    ->all();
            }
        }

        return [
            'A short application and a clear next step after you apply.',
            'Help with growth, coordination, and the day-to-day work of building a nation.',
            'An active alliance that is easy to settle into.',
        ];
    }

    /**
     * @param  array<int, mixed>  $highlights
     */
    public function setHomepageHighlights(array $highlights): void
    {
        $cleaned = collect($highlights)
            ->map(fn ($item) => is_string($item) ? trim($item) : '')
            ->filter()
            ->values()
            ->all();

        $this->settings->set('home_highlights', json_encode($cleaned));
    }

    public function getHomepageStatsIntro(): string
    {
        $default = 'A quick look at where the alliance stands today.';

        return $this->settings->getStringWithoutPersisting('home_stats_intro', $default);
    }

    public function setHomepageStatsIntro(string $intro): void
    {
        $this->settings->set('home_stats_intro', $intro);
    }

    public function getHomepageClosingText(string $allianceName): string
    {
        $default = "If {$allianceName} feels like the right fit, send in your application and come meet the team.";

        return $this->settings->getStringWithoutPersisting('home_closing_text', $default);
    }

    public function setHomepageClosingText(string $text): void
    {
        $this->settings->set('home_closing_text', $text);
    }

    public function getHomepageHeroBadge(): string
    {
        return $this->settings->getStringWithoutPersisting('home_hero_badge', 'Recruiting now');
    }

    public function setHomepageHeroBadge(string $badge): void
    {
        $this->settings->set('home_hero_badge', $badge);
    }

    public function getHomepageCtaLabel(): string
    {
        return $this->settings->getStringWithoutPersisting('home_cta_label', 'Start your application');
    }

    public function setHomepageCtaLabel(string $label): void
    {
        $this->settings->set('home_cta_label', $label);
    }
}
