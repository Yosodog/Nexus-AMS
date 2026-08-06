@php
    $highlightInputs = old('home_highlights', $homepageSettings['highlights'] ?? []);
    $highlightInputs = array_pad($highlightInputs, 3, '');
@endphp

<div class="mb-5">
    <h2 class="nexus-section-title">Public site</h2>
    <p class="mt-1 max-w-3xl text-sm text-base-content/70">
        Manage the copy, identity, search metadata, and browser icon visitors see before they sign in.
    </p>
</div>

<div class="nexus-panel divide-y divide-base-300 overflow-hidden">
    <x-admin.settings-disclosure
        id="homepage-messaging"
        title="Homepage Messaging"
        description="Edit the public homepage headline, supporting copy, calls to action, and recruitment highlights."
        status="Public copy"
        :open="$errors->hasAny(['home_headline', 'home_tagline', 'home_about', 'home_highlights', 'home_stats_intro', 'home_hero_badge', 'home_cta_label', 'home_closing_text'])"
    >
        <form method="POST" action="{{ route('admin.settings.homepage') }}" class="space-y-6">
            @csrf

            <fieldset class="space-y-4">
                <legend class="font-semibold text-base-content">Hero content</legend>

                <div class="grid gap-4 lg:grid-cols-2">
                    <label class="block space-y-2 lg:col-span-2">
                        <span class="text-sm font-medium">Headline</span>
                        <input type="text" class="input w-full" id="homeHeadline" name="home_headline" value="{{ old('home_headline', $homepageSettings['headline'] ?? '') }}" maxlength="160" required>
                    </label>

                    <label class="block space-y-2 lg:col-span-2">
                        <span class="text-sm font-medium">Tagline</span>
                        <input type="text" class="input w-full" id="homeTagline" name="home_tagline" value="{{ old('home_tagline', $homepageSettings['tagline'] ?? '') }}" maxlength="240" required>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Hero badge</span>
                        <input type="text" class="input w-full" id="homeHeroBadge" name="home_hero_badge" value="{{ old('home_hero_badge', $homepageSettings['hero_badge'] ?? '') }}" maxlength="60" placeholder="Recruiting now">
                        <span class="block text-xs text-base-content/60">Short status label shown above the headline.</span>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Primary button label</span>
                        <input type="text" class="input w-full" id="homeCtaLabel" name="home_cta_label" value="{{ old('home_cta_label', $homepageSettings['cta_label'] ?? '') }}" maxlength="60" placeholder="Start your application">
                        <span class="block text-xs text-base-content/60">Text on the main call-to-action button.</span>
                    </label>
                </div>
            </fieldset>

            <fieldset class="space-y-4 border-t border-base-300 pt-6">
                <legend class="font-semibold text-base-content">Supporting content</legend>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">About blurb</span>
                    <textarea class="textarea min-h-28 w-full" id="homeAbout" name="home_about" maxlength="800" placeholder="Short paragraph for guests">{{ old('home_about', $homepageSettings['about'] ?? '') }}</textarea>
                    <span class="text-xs text-base-content/60">A short paragraph near the top of the page.</span>
                </label>

                <div class="grid gap-4 lg:grid-cols-2">
                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Stats intro</span>
                        <input type="text" class="input w-full" id="homeStatsIntro" name="home_stats_intro" value="{{ old('home_stats_intro', $homepageSettings['stats_intro'] ?? '') }}" maxlength="240" placeholder="A quick look at the alliance as it stands today.">
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Closing line</span>
                        <input type="text" class="input w-full" id="homeClosingText" name="home_closing_text" value="{{ old('home_closing_text', $homepageSettings['closing_text'] ?? '') }}" maxlength="300" placeholder="If this feels like the right fit, come meet the team.">
                    </label>
                </div>
            </fieldset>

            <fieldset class="space-y-3 border-t border-base-300 pt-6">
                <legend class="font-semibold text-base-content">Highlights <span class="font-normal text-base-content/60">(optional)</span></legend>
                <div class="grid gap-3 lg:grid-cols-3">
                    @foreach ($highlightInputs as $index => $highlight)
                        <input type="text" class="input w-full" name="home_highlights[]" value="{{ $highlight }}" maxlength="140" placeholder="Clear onboarding and quick responses" aria-label="Homepage highlight {{ $index + 1 }}">
                    @endforeach
                </div>
                <p class="text-xs text-base-content/60">Short points that tell recruits what they can expect.</p>
            </fieldset>

            <div class="flex justify-end border-t border-base-300 pt-5">
                <button class="btn btn-primary" type="submit">Save homepage content</button>
            </div>
        </form>
    </x-admin.settings-disclosure>

    <x-admin.settings-disclosure
        id="search-sharing"
        title="Search & Sharing"
        description="Control how the public alliance site appears in search results and social link previews."
        :status="$seoSettings['effective_indexing_enabled'] ? 'Indexable' : 'Noindex'"
        :status-class="$seoSettings['effective_indexing_enabled'] ? 'badge-success' : 'badge-warning'"
        :open="$errors->hasAny(['indexing_enabled', 'site_name_override', 'alliance_name_override', 'alliance_acronym_override', 'home_title_override', 'home_description_override', 'apply_title_override', 'apply_description_override', 'social_image', 'remove_social_image'])"
    >
        <div class="space-y-6">
            @if ($seoSettings['warnings'] !== [])
                <div class="alert alert-warning">
                    <div>
                        <p class="font-semibold">SEO readiness needs attention</p>
                        <ul class="mt-2 grid gap-1 text-sm">
                            @foreach ($seoSettings['warnings'] as $warning)
                                <li class="flex items-start gap-2">
                                    <span aria-hidden="true">&bull;</span>
                                    <span>{{ $warning }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <dl class="grid gap-x-6 gap-y-4 border-y border-base-300 py-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs font-semibold text-base-content/60">Site name</dt>
                    <dd class="mt-1 break-words font-semibold">{{ $seoSettings['identity']['site_name'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-base-content/60">Alliance</dt>
                    <dd class="mt-1 break-words font-semibold">{{ $seoSettings['identity']['alliance_name'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-base-content/60">Acronym</dt>
                    <dd class="mt-1 font-semibold">{{ $seoSettings['identity']['alliance_acronym'] ?? 'Not available' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-base-content/60">Canonical home</dt>
                    <dd class="mt-1 break-all text-sm font-semibold">{{ $seoSettings['home_metadata']->canonical }}</dd>
                </div>
            </dl>

            <form method="POST" action="{{ route('admin.settings.seo') }}" enctype="multipart/form-data" class="space-y-7">
                @csrf
                <input type="hidden" name="indexing_enabled" value="0">
                <input type="hidden" name="remove_social_image" value="0">

                <label class="flex cursor-pointer items-start gap-3 rounded-box border border-base-300 p-4">
                    <input
                        class="toggle toggle-primary mt-0.5"
                        type="checkbox"
                        id="seoIndexingEnabled"
                        name="indexing_enabled"
                        value="1"
                        @checked(old('indexing_enabled', $seoSettings['configuration']['indexing_enabled']))
                    >
                    <span>
                        <span class="block font-semibold">Allow public pages to appear in search</span>
                        <span class="mt-1 block text-xs text-base-content/60">Environment and canonical URL safety checks remain authoritative.</span>
                    </span>
                </label>

                <fieldset class="space-y-4">
                    <legend class="font-semibold text-base-content">Identity overrides</legend>
                    <p class="text-sm text-base-content/60">Leave a field blank to keep deriving it from the app name and configured primary alliance.</p>

                    <div class="grid gap-4 md:grid-cols-3">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Site name override</span>
                            <input type="text" class="input w-full" name="site_name_override" maxlength="120" value="{{ old('site_name_override', $seoSettings['configuration']['site_name_override']) }}" placeholder="{{ $seoSettings['identity']['site_name'] }}">
                            <span class="text-xs text-base-content/60">Automatic: {{ $seoSettings['identity']['site_name'] }}</span>
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Alliance name override</span>
                            <input type="text" class="input w-full" name="alliance_name_override" maxlength="120" value="{{ old('alliance_name_override', $seoSettings['configuration']['alliance_name_override']) }}" placeholder="{{ $seoSettings['identity']['alliance_name'] }}">
                            <span class="text-xs text-base-content/60">Automatic: {{ $seoSettings['identity']['alliance_name'] }}</span>
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Alliance acronym override</span>
                            <input type="text" class="input w-full" name="alliance_acronym_override" maxlength="20" value="{{ old('alliance_acronym_override', $seoSettings['configuration']['alliance_acronym_override']) }}" placeholder="{{ $seoSettings['identity']['alliance_acronym'] ?? 'None' }}">
                            <span class="text-xs text-base-content/60">Automatic: {{ $seoSettings['identity']['alliance_acronym'] ?? 'none' }}</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="space-y-4 border-t border-base-300 pt-6">
                    <legend class="font-semibold text-base-content">Page metadata overrides</legend>
                    <p class="text-sm text-base-content/60">Blank fields keep the generated titles and descriptions shown below.</p>

                    <div class="grid gap-6 lg:grid-cols-2">
                        <div class="space-y-4">
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Homepage title override</span>
                                <input type="text" class="input w-full" name="home_title_override" maxlength="120" value="{{ old('home_title_override', $seoSettings['configuration']['home_title_override']) }}" placeholder="{{ $seoSettings['home_metadata']->title }}">
                            </label>
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Homepage description override</span>
                                <textarea class="textarea min-h-24 w-full" name="home_description_override" maxlength="320" placeholder="{{ $seoSettings['home_metadata']->description }}">{{ old('home_description_override', $seoSettings['configuration']['home_description_override']) }}</textarea>
                            </label>
                            <div class="rounded-box border border-base-300 bg-base-100 p-4">
                                <p class="text-xs font-semibold text-base-content/60">Homepage preview</p>
                                <p class="mt-2 break-words text-base font-semibold text-primary">{{ $seoSettings['home_metadata']->title }}</p>
                                <p class="mt-1 break-all text-xs text-success">{{ $seoSettings['home_metadata']->canonical }}</p>
                                <p class="mt-2 text-sm leading-6 text-base-content/70">{{ $seoSettings['home_metadata']->description }}</p>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Application title override</span>
                                <input type="text" class="input w-full" name="apply_title_override" maxlength="120" value="{{ old('apply_title_override', $seoSettings['configuration']['apply_title_override']) }}" placeholder="{{ $seoSettings['apply_metadata']->title }}">
                            </label>
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Application description override</span>
                                <textarea class="textarea min-h-24 w-full" name="apply_description_override" maxlength="320" placeholder="{{ $seoSettings['apply_metadata']->description }}">{{ old('apply_description_override', $seoSettings['configuration']['apply_description_override']) }}</textarea>
                            </label>
                            <div class="rounded-box border border-base-300 bg-base-100 p-4">
                                <p class="text-xs font-semibold text-base-content/60">Application preview</p>
                                <p class="mt-2 break-words text-base font-semibold text-primary">{{ $seoSettings['apply_metadata']->title }}</p>
                                <p class="mt-1 break-all text-xs text-success">{{ $seoSettings['apply_metadata']->canonical }}</p>
                                <p class="mt-2 text-sm leading-6 text-base-content/70">{{ $seoSettings['apply_metadata']->description }}</p>
                            </div>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="border-t border-base-300 pt-6">
                    <legend class="font-semibold text-base-content">Social preview image</legend>
                    <p class="mt-1 text-sm text-base-content/60">Current source: {{ $seoSettings['social_image_source'] }}. A custom upload overrides the alliance flag.</p>

                    <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
                        <div class="space-y-4">
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Custom image</span>
                                <input type="file" class="file-input w-full" name="social_image" accept=".png,.jpg,.jpeg,.webp">
                                <span class="text-xs text-base-content/60">PNG, JPG, or WebP up to 5 MB. Recommended: 1200×630.</span>
                            </label>

                            @if ($seoSettings['configuration']['social_image_path'])
                                <label class="flex cursor-pointer items-center gap-3">
                                    <input class="checkbox checkbox-warning" type="checkbox" name="remove_social_image" value="1">
                                    <span>Remove the custom image and return to the alliance flag fallback</span>
                                </label>
                            @endif
                        </div>

                        <div class="grid min-h-40 place-items-center overflow-hidden rounded-box border border-base-300 bg-base-200/50 p-3">
                            @if ($seoSettings['home_metadata']->imageUrl)
                                <img src="{{ $seoSettings['home_metadata']->imageUrl }}" alt="Current social preview" class="max-h-48 w-full object-contain">
                            @else
                                <p class="text-center text-sm text-base-content/60">No social preview image is currently available.</p>
                            @endif
                        </div>
                    </div>
                </fieldset>

                <div class="flex justify-end border-t border-base-300 pt-5">
                    <button class="btn btn-primary" type="submit">Save search & sharing</button>
                </div>
            </form>
        </div>
    </x-admin.settings-disclosure>

    <x-admin.settings-disclosure
        id="favicon-settings"
        title="Favicon"
        description="Upload a square icon used in browser tabs, bookmarks, and app shortcuts."
        status="Branding"
        :open="$errors->has('favicon')"
    >
        <div class="grid gap-6 md:grid-cols-[auto_minmax(0,1fr)] md:items-start">
            <div class="flex h-16 w-16 items-center justify-center rounded-box border border-base-300 bg-base-100">
                <img src="{{ $faviconUrl }}" alt="Current favicon" class="max-h-9 max-w-9">
            </div>

            <form method="POST" action="{{ route('admin.settings.favicon') }}" enctype="multipart/form-data" class="max-w-2xl space-y-4">
                @csrf
                <label class="block space-y-2">
                    <span class="text-sm font-medium">Favicon file</span>
                    <input type="file" class="file-input w-full" id="faviconUpload" name="favicon" accept=".png,.ico,.jpg,.jpeg" required>
                    <span class="text-xs text-base-content/60">PNG, ICO, or JPG. Recommended: 32×32 or 64×64.</span>
                </label>

                <button class="btn btn-primary" type="submit">Upload favicon</button>
            </form>
        </div>
    </x-admin.settings-disclosure>
</div>
