<?php

namespace App\Services;

use App\DataTransferObjects\SeoMetadata;
use App\Models\Alliance;
use App\Models\Page;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeoService
{
    private const SETTINGS_KEY = 'seo_configuration';

    private const APPLY_PAGE_SLUG = 'apply';

    /**
     * @var array<int, string>
     */
    private const OVERRIDE_KEYS = [
        'site_name_override',
        'alliance_name_override',
        'alliance_acronym_override',
        'home_title_override',
        'home_description_override',
        'apply_title_override',
        'apply_description_override',
    ];

    public function __construct(private readonly AllianceMembershipService $membershipService) {}

    /**
     * @return array{
     *     indexing_enabled: bool,
     *     site_name_override: string|null,
     *     alliance_name_override: string|null,
     *     alliance_acronym_override: string|null,
     *     home_title_override: string|null,
     *     home_description_override: string|null,
     *     apply_title_override: string|null,
     *     apply_description_override: string|null,
     *     social_image_path: string|null
     * }
     */
    public function configuration(): array
    {
        $stored = SettingService::getValue(self::SETTINGS_KEY);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        $decoded = is_array($decoded) ? $decoded : [];

        $configuration = [
            'indexing_enabled' => $this->normalizeBoolean($decoded['indexing_enabled'] ?? true, true),
            'social_image_path' => $this->normalizeNullableString($decoded['social_image_path'] ?? null),
        ];

        foreach (self::OVERRIDE_KEYS as $key) {
            $configuration[$key] = $this->normalizeNullableString($decoded[$key] ?? null);
        }

        /** @var array{
         *     indexing_enabled: bool,
         *     site_name_override: string|null,
         *     alliance_name_override: string|null,
         *     alliance_acronym_override: string|null,
         *     home_title_override: string|null,
         *     home_description_override: string|null,
         *     apply_title_override: string|null,
         *     apply_description_override: string|null,
         *     social_image_path: string|null
         * } $configuration
         */
        return $configuration;
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function saveConfiguration(array $configuration): void
    {
        $normalized = [
            'indexing_enabled' => $this->normalizeBoolean($configuration['indexing_enabled'] ?? true, true),
            'social_image_path' => $this->normalizeNullableString($configuration['social_image_path'] ?? null),
        ];

        foreach (self::OVERRIDE_KEYS as $key) {
            $normalized[$key] = $this->normalizeNullableString($configuration[$key] ?? null);
        }

        SettingService::setValue(
            self::SETTINGS_KEY,
            json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    public function primaryAlliance(): ?Alliance
    {
        $allianceId = $this->membershipService->getPrimaryAllianceId();

        return $allianceId > 0 ? Alliance::query()->find($allianceId) : null;
    }

    /**
     * @return array{
     *     site_name: string,
     *     alliance_name: string,
     *     alliance_acronym: string|null,
     *     alliance_label: string,
     *     alliance: Alliance|null
     * }
     */
    public function resolvedIdentity(?Alliance $alliance = null): array
    {
        $configuration = $this->configuration();
        $alliance ??= $this->primaryAlliance();

        $configuredAppName = trim((string) config('app.name', 'Nexus AMS'));
        $appName = $configuredAppName !== '' ? $configuredAppName : 'Nexus AMS';
        $siteName = $configuration['site_name_override'] ?? $appName;
        $allianceName = $configuration['alliance_name_override']
            ?? $this->normalizeNullableString($alliance?->name)
            ?? $siteName;
        $allianceAcronym = $configuration['alliance_acronym_override']
            ?? $this->normalizeNullableString($alliance?->acronym);

        return [
            'site_name' => $siteName,
            'alliance_name' => $allianceName,
            'alliance_acronym' => $allianceAcronym,
            'alliance_label' => $this->allianceLabel($allianceName, $allianceAcronym),
            'alliance' => $alliance,
        ];
    }

    public function homeMetadata(?Alliance $alliance, string $homepageTagline): SeoMetadata
    {
        $configuration = $this->configuration();
        $identity = $this->resolvedIdentity($alliance);
        $canonical = route('home');
        $indexable = $this->isGlobalIndexingEnabled();
        $title = $configuration['home_title_override']
            ?? $this->defaultHomeTitle($identity['alliance_label'], $identity['site_name'], $identity['alliance_name'], $identity['alliance_acronym']);
        $description = $configuration['home_description_override']
            ?? $this->defaultHomeDescription($homepageTagline, $identity['alliance_label']);
        $imageUrl = $this->resolvedSocialImage($configuration, $identity['alliance']);

        return new SeoMetadata(
            title: $title,
            description: $description,
            canonical: $canonical,
            robots: $indexable ? 'index, follow' : 'noindex, nofollow',
            siteName: $identity['site_name'],
            indexable: $indexable,
            imageUrl: $imageUrl,
            imageAlt: $imageUrl === null ? null : $identity['alliance_name'].' alliance preview',
            structuredData: $this->structuredData($identity, $canonical, $imageUrl),
        );
    }

    public function applyMetadata(?Alliance $alliance = null): SeoMetadata
    {
        $configuration = $this->configuration();
        $identity = $this->resolvedIdentity($alliance);
        $canonical = route('apply.show');
        $indexable = $this->isGlobalIndexingEnabled() && $this->hasPublishedApplyContent();
        $title = $configuration['apply_title_override']
            ?? $this->defaultApplyTitle($identity['alliance_label'], $identity['site_name'], $identity['alliance_name'], $identity['alliance_acronym']);
        $description = $configuration['apply_description_override']
            ?? $this->defaultApplyDescription($identity['alliance_label'], $identity['site_name'], $identity['alliance_name']);
        $imageUrl = $this->resolvedSocialImage($configuration, $identity['alliance']);

        return new SeoMetadata(
            title: $title,
            description: $description,
            canonical: $canonical,
            robots: $indexable ? 'index, follow' : 'noindex, nofollow',
            siteName: $identity['site_name'],
            indexable: $indexable,
            imageUrl: $imageUrl,
            imageAlt: $imageUrl === null ? null : $identity['alliance_name'].' alliance preview',
        );
    }

    public function isGlobalIndexingEnabled(): bool
    {
        return (bool) config('seo.indexing_enabled')
            && $this->configuration()['indexing_enabled']
            && $this->hasPublicCanonicalUrl();
    }

    public function isRouteIndexable(?string $routeName): bool
    {
        if ($routeName === 'home') {
            return $this->isGlobalIndexingEnabled();
        }

        if ($routeName === 'apply.show') {
            return $this->isGlobalIndexingEnabled() && $this->hasPublishedApplyContent();
        }

        return false;
    }

    public function hasPublishedApplyContent(): bool
    {
        $published = Page::query()
            ->where('slug', self::APPLY_PAGE_SLUG)
            ->value('published');

        return is_string($published) && trim($published) !== '';
    }

    public function hasPublicCanonicalUrl(): bool
    {
        $applicationUrl = trim((string) config('app.url'));

        if (filter_var($applicationUrl, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($applicationUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($applicationUrl, PHP_URL_HOST));

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        if ($host === 'localhost' || Str::endsWith($host, ['.localhost', '.test', '.local'])) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    public function robotsContent(): string
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
        ];

        if ($this->isGlobalIndexingEnabled()) {
            $lines[] = 'Sitemap: '.route('seo.sitemap');
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array<int, string>
     */
    public function sitemapUrls(): array
    {
        if (! $this->isGlobalIndexingEnabled()) {
            return [];
        }

        return array_values(array_filter([
            route('home'),
            $this->hasPublishedApplyContent() ? route('apply.show') : null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsContext(string $homepageTagline): array
    {
        $configuration = $this->configuration();
        $identity = $this->resolvedIdentity();
        $homeMetadata = $this->homeMetadata($identity['alliance'], $homepageTagline);
        $applyMetadata = $this->applyMetadata($identity['alliance']);
        $environmentEnabled = (bool) config('seo.indexing_enabled');
        $canonicalReady = $this->hasPublicCanonicalUrl();
        $applyPublished = $this->hasPublishedApplyContent();
        $warnings = [];

        if (! $environmentEnabled) {
            $warnings[] = 'Search indexing is disabled by the environment configuration.';
        }

        if (! $configuration['indexing_enabled']) {
            $warnings[] = 'Search indexing is disabled by the admin setting.';
        }

        if (! $canonicalReady) {
            $warnings[] = 'APP_URL must be a public HTTPS origin before pages can be indexed.';
        }

        if ($identity['alliance'] === null) {
            $warnings[] = 'The configured primary alliance has not been synchronized; site-name fallbacks are active.';
        }

        if (! $applyPublished) {
            $warnings[] = 'The application page has no published content and will stay out of the sitemap.';
        }

        if ($configuration['social_image_path'] !== null
            && ! Storage::disk('public')->exists($configuration['social_image_path'])) {
            $warnings[] = 'The configured custom social image is missing; the alliance flag fallback is active.';
        }

        return [
            'configuration' => $configuration,
            'identity' => $identity,
            'home_metadata' => $homeMetadata,
            'apply_metadata' => $applyMetadata,
            'effective_indexing_enabled' => $this->isGlobalIndexingEnabled(),
            'environment_enabled' => $environmentEnabled,
            'canonical_ready' => $canonicalReady,
            'apply_published' => $applyPublished,
            'social_image_source' => $this->socialImageSource($configuration, $identity['alliance']),
            'warnings' => $warnings,
        ];
    }

    public function safePublicUrl(?string $url): ?string
    {
        $url = is_string($url) ? trim($url) : '';

        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }

    private function normalizeBoolean(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return is_bool($normalized) ? $normalized : $default;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function allianceLabel(string $allianceName, ?string $allianceAcronym): string
    {
        if ($allianceAcronym === null || mb_stripos($allianceName, $allianceAcronym) !== false) {
            return $allianceName;
        }

        return sprintf('%s (%s)', $allianceName, $allianceAcronym);
    }

    private function defaultHomeTitle(
        string $allianceLabel,
        string $siteName,
        string $allianceName,
        ?string $allianceAcronym,
    ): string {
        $title = $allianceLabel.' — Politics & War Alliance';

        return $this->isDuplicateBrand($siteName, $allianceLabel, $allianceName, $allianceAcronym)
            ? $title
            : $title.' | '.$siteName;
    }

    private function defaultApplyTitle(
        string $allianceLabel,
        string $siteName,
        string $allianceName,
        ?string $allianceAcronym,
    ): string {
        $title = 'Apply to '.$allianceLabel;

        return $this->isDuplicateBrand($siteName, $allianceLabel, $allianceName, $allianceAcronym)
            ? $title
            : $title.' | '.$siteName;
    }

    private function defaultHomeDescription(string $homepageTagline, string $allianceLabel): string
    {
        $tagline = $this->normalizeDescription($homepageTagline);

        if ($tagline !== '') {
            return Str::limit($tagline, 200, '…', true);
        }

        return sprintf(
            'Explore %s in Politics & War, review current alliance statistics, and learn how to apply.',
            $allianceLabel,
        );
    }

    private function defaultApplyDescription(string $allianceLabel, string $siteName, string $allianceName): string
    {
        if ($this->normalizeComparable($siteName) === $this->normalizeComparable($allianceName)) {
            return sprintf(
                'Learn who can apply to %s, then start in Politics & War and complete the application in Discord.',
                $allianceLabel,
            );
        }

        return sprintf(
            'Learn who can apply to %s, then start in Politics & War and complete the Discord application through %s.',
            $allianceLabel,
            $siteName,
        );
    }

    private function normalizeDescription(string $description): string
    {
        $description = strip_tags($description);

        return trim((string) preg_replace('/\s+/u', ' ', $description));
    }

    private function isDuplicateBrand(
        string $siteName,
        string $allianceLabel,
        string $allianceName,
        ?string $allianceAcronym,
    ): bool {
        $site = $this->normalizeComparable($siteName);
        $candidates = [$allianceLabel, $allianceName, $allianceAcronym];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $site === $this->normalizeComparable($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeComparable(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/[^\pL\pN]+/u', '', $value)));
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function resolvedSocialImage(array $configuration, ?Alliance $alliance): ?string
    {
        $customPath = $configuration['social_image_path'] ?? null;

        if (is_string($customPath) && Storage::disk('public')->exists($customPath)) {
            return $this->absoluteApplicationUrl(Storage::disk('public')->url($customPath));
        }

        return $this->safePublicUrl($alliance?->flag);
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function socialImageSource(array $configuration, ?Alliance $alliance): string
    {
        $customPath = $configuration['social_image_path'] ?? null;

        if (is_string($customPath) && Storage::disk('public')->exists($customPath)) {
            return 'Custom upload';
        }

        if ($this->safePublicUrl($alliance?->flag) !== null) {
            return 'Alliance flag';
        }

        return 'None';
    }

    private function absoluteApplicationUrl(string $path): string
    {
        if ($this->safePublicUrl($path) !== null) {
            return $path;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * @param  array{
     *     site_name: string,
     *     alliance_name: string,
     *     alliance_acronym: string|null,
     *     alliance_label: string,
     *     alliance: Alliance|null
     * }  $identity
     * @return array<string, mixed>
     */
    private function structuredData(array $identity, string $canonical, ?string $imageUrl): array
    {
        $organizationId = $canonical.'#organization';
        $websiteId = $canonical.'#website';
        $alliance = $identity['alliance'];
        $sameAs = [];

        if ($alliance !== null) {
            $sameAs[] = 'https://politicsandwar.com/alliance/id='.$alliance->getKey();

            foreach ([$alliance->wiki_link, $alliance->forum_link, $alliance->discord_link] as $url) {
                $safeUrl = $this->safePublicUrl($url);

                if ($safeUrl !== null) {
                    $sameAs[] = $safeUrl;
                }
            }
        }

        $websiteAlternateNames = collect([
            $identity['alliance_name'],
            $identity['alliance_acronym'],
        ])->filter(fn (?string $name): bool => $name !== null
            && $this->normalizeComparable($name) !== $this->normalizeComparable($identity['site_name']))
            ->unique()
            ->values()
            ->all();

        $website = array_filter([
            '@type' => 'WebSite',
            '@id' => $websiteId,
            'url' => $canonical,
            'name' => $identity['site_name'],
            'alternateName' => $websiteAlternateNames === [] ? null : $websiteAlternateNames,
            'publisher' => ['@id' => $organizationId],
        ], fn (mixed $value): bool => $value !== null);

        $organization = array_filter([
            '@type' => 'Organization',
            '@id' => $organizationId,
            'url' => $canonical,
            'name' => $identity['alliance_name'],
            'alternateName' => $identity['alliance_acronym'],
            'image' => $imageUrl,
            'sameAs' => $sameAs === [] ? null : array_values(array_unique($sameAs)),
        ], fn (mixed $value): bool => $value !== null);

        return [
            '@context' => 'https://schema.org',
            '@graph' => [$website, $organization],
        ];
    }
}
