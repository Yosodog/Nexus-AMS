<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateDiscordBotAPI
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $botToken = config('services.discord_bot_key');
        $providedToken = trim((string) $request->header('Authorization', ''));

        if (empty($botToken) || $providedToken !== "Bearer {$botToken}") {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
