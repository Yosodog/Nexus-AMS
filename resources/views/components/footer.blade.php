<footer class="site-footer">
    <div class="site-footer__inner">
        <div class="site-footer__identity">
            <span class="public-brand__mark" aria-hidden="true">N</span>
            <div>
                <p class="site-footer__name">{{ config('app.name') }}</p>
                <p class="site-footer__summary">Member services and alliance operations.</p>
            </div>
        </div>

        <nav class="site-footer__links" aria-label="Footer navigation">
            <a href="{{ route('home') }}">Overview</a>
            <a href="{{ route('apply.show') }}">Apply</a>
            @auth
                <a href="{{ route('user.dashboard') }}">Member app</a>
            @else
                <a href="{{ route('login') }}">Sign in</a>
            @endauth
        </nav>

        <p class="site-footer__credit">
            Powered by <a href="https://github.com/Yosodog/Nexus-AMS" rel="noopener" target="_blank">Nexus AMS</a>.
        </p>
    </div>
</footer>
