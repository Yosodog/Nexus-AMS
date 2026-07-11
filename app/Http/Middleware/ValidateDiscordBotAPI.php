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

        $expectedToken = 'Bearer '.(string) $botToken;

        if (empty($botToken) || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'error' => ['code' => 'unauthorized', 'message' => 'Discord bot authentication failed.'],
                'meta' => ['contract_version' => 1],
            ], 401);
        }

        return $next($request);
    }
}
