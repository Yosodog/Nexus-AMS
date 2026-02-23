<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $isLocal = app()->isLocal();

        $response->headers->set('X-Content-Type-Options', 'nosniff', false);
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
            false
        );

        if (! $isLocal && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains', false);
        }

        $sourceProtocols = $isLocal ? "'self' http: https:" : "'self' https:";
        $connectProtocols = $isLocal ? "'self' http: https: ws: wss:" : "'self' https: wss:";

        $csp = implode('; ', [
            "default-src {$sourceProtocols}",
            "base-uri 'self'",
            "object-src 'none'",
            "script-src {$sourceProtocols} 'unsafe-inline' 'unsafe-eval'",
            "style-src {$sourceProtocols} 'unsafe-inline'",
            "img-src {$sourceProtocols} data:",
            "font-src {$sourceProtocols} data:",
            "connect-src {$connectProtocols}",
            "frame-src 'self' https://discord.com https://*.discord.com https://discordapp.com https://*.discordapp.com",
            "frame-ancestors 'self'",
            "form-action 'self'",
        ]);

        if (! $isLocal) {
            $csp .= '; upgrade-insecure-requests';
        }

        $response->headers->set('Content-Security-Policy', $csp, false);

        return $response;
    }
}
