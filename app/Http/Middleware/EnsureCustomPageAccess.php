<?php

namespace App\Http\Middleware;

use App\Models\Page;
use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomPageAccess
{
    public function __construct(private readonly Pipeline $pipeline) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $page = Page::query()->where('slug', $request->route('slug'))->firstOrFail();

        abort_if($page->slug === 'apply' || ! $page->hasPublishedContent(), 404);

        $audience = $page->published_metadata['audience'] ?? null;
        abort_unless(in_array($audience, ['public', 'member'], true), 404);

        $request->attributes->set('custom_page', $page);

        if ($audience === 'public') {
            return $next($request);
        }

        return $this->pipeline
            ->send($request)
            ->through([
                Authenticate::class,
                EnsureUserIsVerified::class,
                DiscordVerifiedMiddleware::class,
                EnsureMfaConfigured::class,
                EnsureEligibleCustomPageMember::class,
            ])
            ->then(fn (Request $request): Response => $next($request));
    }
}
