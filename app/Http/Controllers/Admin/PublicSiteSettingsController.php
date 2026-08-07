<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFaviconRequest;
use App\Http\Requests\Admin\UpdateHomepageSettingsRequest;
use App\Http\Requests\Admin\UpdateSeoSettingsRequest;
use App\Services\AuditLogger;
use App\Services\SeoService;
use App\Services\Settings\PublicSiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PublicSiteSettingsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PublicSiteSettings $settings,
        private readonly SeoService $seoService,
    ) {}

    public function index(): View
    {
        $this->authorize('view-diagnostic-info');

        $seoIdentity = $this->seoService->resolvedIdentity();
        $allianceName = $seoIdentity['alliance_name'] ?? (string) config('app.name');
        $homepageSettings = [
            'headline' => $this->settings->getHomepageHeadline($allianceName),
            'tagline' => $this->settings->getHomepageTagline($allianceName),
            'about' => $this->settings->getHomepageAbout($allianceName),
            'highlights' => $this->settings->getHomepageHighlights(),
            'stats_intro' => $this->settings->getHomepageStatsIntro(),
            'hero_badge' => $this->settings->getHomepageHeroBadge(),
            'cta_label' => $this->settings->getHomepageCtaLabel(),
            'closing_text' => $this->settings->getHomepageClosingText($allianceName),
        ];

        return view('admin.settings.public-site', [
            'homepageSettings' => $homepageSettings,
            'seoSettings' => $this->seoService->settingsContext($homepageSettings['tagline']),
        ]);
    }

    public function updateHomepage(UpdateHomepageSettingsRequest $request): RedirectResponse
    {
        $previous = [
            'home_headline' => $this->settings->getHomepageHeadline(config('app.name')),
            'home_tagline' => $this->settings->getHomepageTagline(config('app.name')),
            'home_about' => $this->settings->getHomepageAbout(config('app.name')),
            'home_highlights' => $this->settings->getHomepageHighlights(),
            'home_stats_intro' => $this->settings->getHomepageStatsIntro(),
            'home_closing_text' => $this->settings->getHomepageClosingText(config('app.name')),
            'home_hero_badge' => $this->settings->getHomepageHeroBadge(),
            'home_cta_label' => $this->settings->getHomepageCtaLabel(),
        ];
        $validated = $request->validated();

        $highlights = collect($validated['home_highlights'] ?? [])
            ->map(fn ($item): string => (string) $item)
            ->all();

        DB::transaction(function () use ($validated, $highlights): void {
            $this->settings->setHomepageHeadline($validated['home_headline']);
            $this->settings->setHomepageTagline($validated['home_tagline']);
            $this->settings->setHomepageAbout($validated['home_about'] ?? '');
            $this->settings->setHomepageStatsIntro($validated['home_stats_intro'] ?? '');
            $this->settings->setHomepageClosingText($validated['home_closing_text'] ?? '');
            $this->settings->setHomepageHeroBadge($validated['home_hero_badge'] ?? 'Recruiting now');
            $this->settings->setHomepageCtaLabel($validated['home_cta_label'] ?? 'Start your application');
            $this->settings->setHomepageHighlights($highlights);
        });

        $this->auditLogger->success(
            category: 'settings',
            action: 'homepage_settings_updated',
            context: [
                'changes' => [
                    'home_headline' => ['from' => $previous['home_headline'], 'to' => $validated['home_headline']],
                    'home_tagline' => ['from' => $previous['home_tagline'], 'to' => $validated['home_tagline']],
                    'home_about' => ['from' => $previous['home_about'], 'to' => $validated['home_about'] ?? ''],
                    'home_stats_intro' => ['from' => $previous['home_stats_intro'], 'to' => $validated['home_stats_intro'] ?? ''],
                    'home_closing_text' => ['from' => $previous['home_closing_text'], 'to' => $validated['home_closing_text'] ?? ''],
                    'home_hero_badge' => ['from' => $previous['home_hero_badge'], 'to' => $validated['home_hero_badge'] ?? 'Recruiting now'],
                    'home_cta_label' => ['from' => $previous['home_cta_label'], 'to' => $validated['home_cta_label'] ?? 'Start your application'],
                    'home_highlights' => ['from' => $previous['home_highlights'], 'to' => $highlights],
                ],
            ],
            message: 'Homepage settings updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Homepage content updated.',
            'alert-type' => 'success',
        ]);
    }

    public function updateFavicon(StoreFaviconRequest $request): RedirectResponse
    {
        $file = $request->file('favicon');
        $extension = $this->faviconExtension($file);
        $path = $file->storeAs('branding', "favicon.{$extension}", 'public');
        $previousPath = $this->settings->getFaviconPath();

        if ($previousPath && $previousPath !== $path && Storage::disk('public')->exists($previousPath)) {
            Storage::disk('public')->delete($previousPath);
        }

        $this->settings->setFaviconPath($path);

        $this->auditLogger->success(
            category: 'settings',
            action: 'favicon_updated',
            context: [
                'changes' => [
                    'favicon_path' => [
                        'from' => $previousPath,
                        'to' => $path,
                    ],
                ],
            ],
            message: 'Favicon updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Favicon updated.',
            'alert-type' => 'success',
        ]);
    }

    public function updateSeo(UpdateSeoSettingsRequest $request): RedirectResponse
    {
        $previous = $this->seoService->configuration();
        $validated = $request->validated();
        $next = $previous;

        foreach ([
            'site_name_override',
            'alliance_name_override',
            'alliance_acronym_override',
            'home_title_override',
            'home_description_override',
            'apply_title_override',
            'apply_description_override',
        ] as $key) {
            $next[$key] = $validated[$key] ?? null;
        }

        $next['indexing_enabled'] = (bool) $validated['indexing_enabled'];
        $previousImagePath = $previous['social_image_path'];
        $nextImagePath = $previousImagePath;
        $storedImagePath = null;
        $file = $request->file('social_image');

        if ($file instanceof UploadedFile) {
            $extension = $this->socialImageExtension($file);
            $storedImagePath = $file->storeAs(
                'branding',
                'seo-social-'.Str::uuid().'.'.$extension,
                'public',
            );

            if (! is_string($storedImagePath)) {
                throw ValidationException::withMessages([
                    'social_image' => 'The social image could not be stored.',
                ]);
            }

            $nextImagePath = $storedImagePath;
        } elseif ((bool) $validated['remove_social_image']) {
            $nextImagePath = null;
        }

        $next['social_image_path'] = $nextImagePath;

        try {
            $this->seoService->saveConfiguration($next);
        } catch (Throwable $exception) {
            if ($storedImagePath !== null && Storage::disk('public')->exists($storedImagePath)) {
                Storage::disk('public')->delete($storedImagePath);
            }

            throw $exception;
        }

        if ($previousImagePath !== null
            && $previousImagePath !== $nextImagePath
            && Storage::disk('public')->exists($previousImagePath)) {
            Storage::disk('public')->delete($previousImagePath);
        }

        $changes = [];

        foreach ($next as $key => $value) {
            if (($previous[$key] ?? null) !== $value) {
                $changes[$key] = [
                    'from' => $previous[$key] ?? null,
                    'to' => $value,
                ];
            }
        }

        $this->auditLogger->success(
            category: 'settings',
            action: 'seo_settings_updated',
            context: ['changes' => $changes],
            message: 'Search and sharing settings updated.',
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Search and sharing settings updated.',
            'alert-type' => 'success',
        ]);
    }

    private function faviconExtension(UploadedFile $file): string
    {
        return match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            default => throw ValidationException::withMessages([
                'favicon' => 'The favicon must be a PNG, JPG, or ICO file.',
            ]),
        };
    }

    private function socialImageExtension(UploadedFile $file): string
    {
        return match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => throw ValidationException::withMessages([
                'social_image' => 'The social image must be a PNG, JPG, or WebP file.',
            ]),
        };
    }
}
