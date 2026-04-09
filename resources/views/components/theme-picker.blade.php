<div class="dropdown dropdown-end">
    <button tabindex="0" class="btn btn-ghost btn-circle" aria-label="Choose theme mode">
        <x-icon name="o-swatch" class="size-5" />
    </button>

    <div tabindex="0" class="dropdown-content z-[90] mt-2 w-72 rounded-2xl border border-base-300 bg-base-100 p-3 shadow-2xl">
        <div class="mb-3">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-base-content/55">Theme Mode</div>
            <p class="mt-1 text-sm text-base-content/60">Switch the admin UI between system, light, and night modes.</p>
        </div>

        <div class="space-y-2">
            <button
                type="button"
                data-theme-mode="auto"
                class="flex w-full items-center justify-between rounded-2xl border border-base-300 bg-base-100 px-4 py-3 text-left transition hover:border-primary/40 hover:bg-base-200"
            >
                <span class="min-w-0">
                    <span class="block font-semibold text-base-content">Auto</span>
                    <span class="block text-xs text-base-content/60">Follow your system preference.</span>
                </span>
                <span class="badge badge-ghost badge-sm shrink-0">System</span>
            </button>

            <button
                type="button"
                data-theme-mode="light"
                class="flex w-full items-center justify-between rounded-2xl border border-base-300 bg-base-100 px-4 py-3 text-left transition hover:border-primary/40 hover:bg-base-200"
            >
                <span class="min-w-0">
                    <span class="block font-semibold text-base-content">Light</span>
                    <span class="block text-xs text-base-content/60">Bright surfaces with higher contrast.</span>
                </span>
                <span class="flex shrink-0 items-center gap-1">
                    <span class="h-3 w-3 rounded-full bg-slate-100 ring-1 ring-slate-300"></span>
                    <span class="badge badge-ghost badge-sm">Light</span>
                </span>
            </button>

            <button
                type="button"
                data-theme-mode="night"
                class="flex w-full items-center justify-between rounded-2xl border border-base-300 bg-base-100 px-4 py-3 text-left transition hover:border-primary/40 hover:bg-base-200"
            >
                <span class="min-w-0">
                    <span class="block font-semibold text-base-content">Night</span>
                    <span class="block text-xs text-base-content/60">Dark surfaces tuned for lower glare.</span>
                </span>
                <span class="flex shrink-0 items-center gap-1">
                    <span class="h-3 w-3 rounded-full bg-slate-900 ring-1 ring-slate-600"></span>
                    <span class="badge badge-ghost badge-sm">Dark</span>
                </span>
            </button>
        </div>
    </div>
</div>
