<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Services\AllianceMembershipService;
use App\Services\PageRenderer;
use App\Services\SeoService;
use App\Services\SettingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Expose the public application landing page driven by the CMS.
 */
class ApplyPageController extends Controller
{
    private const CACHE_KEY = 'pages:apply:html';

    private const CACHE_TTL_MINUTES = 5;

    private const CAMPAIGN_PARAMETERS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'ref',
    ];

    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly SeoService $seoService,
        private readonly AllianceMembershipService $membershipService,
    ) {}

    /**
     * Display the published Apply page, falling back gracefully when no content exists.
     */
    public function show(Request $request): View
    {
        $expiresAt = now()->addMinutes(self::CACHE_TTL_MINUTES);

        $content = Cache::remember(self::CACHE_KEY, $expiresAt, function (): ?string {
            $page = Page::query()
                ->where('slug', 'apply')
                ->first();

            if (! $page) {
                return null;
            }

            if (is_string($page->cached_html) && trim($page->cached_html) !== '') {
                return $page->cached_html;
            }

            $published = is_string($page->published) ? $page->published : '';

            if ($published === '') {
                return null;
            }

            $html = $this->renderer->render($published);
            $page->forceFill(['cached_html' => $html])->save();

            return $html;
        });

        $alliance = $this->seoService->primaryAlliance();
        $identity = $this->seoService->resolvedIdentity($alliance);
        $applicationsOpen = SettingService::isApplicationsEnabled();
        $primaryAllianceId = $this->membershipService->getPrimaryAllianceId();
        $campaignContext = $this->campaignContext($request);

        return view('pages.apply', [
            'title' => 'Apply',
            'content' => $content,
            'seo' => $this->seoService->applyMetadata($alliance),
            'allianceName' => $identity['alliance_name'],
            'applicationsOpen' => $applicationsOpen,
            'applicationStartUrl' => $applicationsOpen && $primaryAllianceId > 0
                ? route('apply.start', $campaignContext)
                : null,
            'discordUrl' => $this->seoService->safePublicUrl($alliance?->discord_link),
            'existingMemberRegistrationUrl' => route('apply.member-registration', $campaignContext),
        ]);
    }

    /**
     * Send an applicant to the first required external step.
     */
    public function start(Request $request): RedirectResponse
    {
        $campaignContext = $this->campaignContext($request);

        if (! SettingService::isApplicationsEnabled()) {
            return redirect()
                ->route('apply.show', $campaignContext)
                ->with([
                    'alert-type' => 'warning',
                    'alert-message' => 'Applications are currently paused. Please review the published requirements or check back later.',
                ]);
        }

        $primaryAllianceId = $this->membershipService->getPrimaryAllianceId();

        if ($primaryAllianceId <= 0) {
            return redirect()
                ->route('apply.show', $campaignContext)
                ->with([
                    'alert-type' => 'warning',
                    'alert-message' => 'The alliance application link is not available right now. Please use the published recruitment instructions to contact staff.',
                ]);
        }

        Log::info('Recruitment funnel event.', [
            'event' => 'applicant_start_clicked',
            'destination' => 'politics_and_war',
            'campaign' => $campaignContext,
        ]);

        return redirect()->away(sprintf(
            'https://politicsandwar.com/alliance/join/id=%d',
            $primaryAllianceId,
        ));
    }

    /**
     * Keep member account registration separate from the applicant funnel.
     */
    public function memberRegistration(Request $request): RedirectResponse
    {
        Log::info('Recruitment funnel event.', [
            'event' => 'existing_member_registration_selected',
            'campaign' => $this->campaignContext($request),
        ]);

        return redirect()->route('register');
    }

    /**
     * Retain a small allowlist of non-identifying campaign values for aggregate funnel reporting.
     *
     * @return array<string, string>
     */
    private function campaignContext(Request $request): array
    {
        return collect(self::CAMPAIGN_PARAMETERS)
            ->mapWithKeys(function (string $parameter) use ($request): array {
                $value = $request->query($parameter);

                if (! is_string($value)) {
                    return [];
                }

                $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($value)) ?? '';
                $sanitized = trim(Str::limit($sanitized, 80, ''), '-');

                return $sanitized === '' ? [] : [$parameter => $sanitized];
            })
            ->all();
    }
}
