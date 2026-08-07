<div
    class="nexus-global-async-status"
    data-async-global-status
    role="status"
    aria-live="polite"
    aria-atomic="true"
    hidden
>
    <div class="nexus-global-async-status__inner" data-async-global-state="offline" hidden>
        <x-icon name="o-wifi" class="size-5 shrink-0" aria-hidden="true" />
        <p><strong>You are offline.</strong> Changes cannot be confirmed until the connection returns.</p>
    </div>
    <div class="nexus-global-async-status__inner" data-async-global-state="session_expired" hidden>
        <x-icon name="o-clock" class="size-5 shrink-0" aria-hidden="true" />
        <p><strong>Your session expired.</strong> Reload and sign in again before continuing.</p>
        <button type="button" class="btn btn-sm btn-outline" data-session-reload>Reload page</button>
    </div>
</div>

<div class="sr-only" data-async-live-region role="status" aria-live="polite" aria-atomic="true"></div>
