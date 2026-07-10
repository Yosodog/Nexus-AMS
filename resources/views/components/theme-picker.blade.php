<details class="theme-control">
    <summary class="btn btn-ghost btn-circle btn-sm" aria-label="Choose appearance">
        <x-icon name="o-swatch" class="size-5" />
    </summary>

    <div class="theme-control__panel" aria-label="Appearance options">
        <div class="theme-control__header">
            <p class="nexus-kicker">Appearance</p>
            <p>Use your device setting or choose a mode for this browser.</p>
        </div>

        <div class="theme-control__options">
            <button type="button" data-theme-mode="auto" class="theme-option">
                <x-icon name="o-computer-desktop" class="size-5" aria-hidden="true" />
                <span>
                    <span class="theme-option__label">System</span>
                    <span class="theme-option__hint">Follow this device</span>
                </span>
                <x-icon name="o-check" class="theme-option__check size-4" aria-hidden="true" />
            </button>

            <button type="button" data-theme-mode="light" class="theme-option">
                <x-icon name="o-sun" class="size-5" aria-hidden="true" />
                <span>
                    <span class="theme-option__label">Light</span>
                    <span class="theme-option__hint">Bright workspaces</span>
                </span>
                <x-icon name="o-check" class="theme-option__check size-4" aria-hidden="true" />
            </button>

            <button type="button" data-theme-mode="night" class="theme-option">
                <x-icon name="o-moon" class="size-5" aria-hidden="true" />
                <span>
                    <span class="theme-option__label">Dark</span>
                    <span class="theme-option__hint">Lower-glare workspaces</span>
                </span>
                <x-icon name="o-check" class="theme-option__check size-4" aria-hidden="true" />
            </button>
        </div>
    </div>
</details>
