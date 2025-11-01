<?php

namespace App\Http\Middleware;

use App\Services\PWHealthService;
use Closure;
use Illuminate\Http\Request;

readonly class BlockWhenPWDown
{
    public function __construct(private PWHealthService $status) {}

    /**
     * @return \Illuminate\Http\RedirectResponse|mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if ($this->status->isDown()) {
            return redirect()
                ->back()
                ->with([
                    'alert-message' => 'This action is currently restricted due to PW API issues. Please try again soon.',
                    'alert-type' => 'error',
                ]);
        }

        return $next($request);
    }
}
