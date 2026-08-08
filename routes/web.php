<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use Illuminate\Support\Facades\Route;

require __DIR__.'/web/public.php';
require __DIR__.'/web/authentication.php';

Route::middleware(['auth', EnsureUserIsVerified::class, DiscordVerifiedMiddleware::class, EnsureMfaConfigured::class])->group(callback: function () {
    require __DIR__.'/web/member/settings.php';
    require __DIR__.'/web/member/finance.php';
    require __DIR__.'/web/member/audits.php';
    require __DIR__.'/web/member/defense.php';
    require __DIR__.'/web/member/grants.php';
});

Route::middleware(['auth', EnsureUserIsVerified::class, DiscordVerifiedMiddleware::class, EnsureMfaConfigured::class, AdminMiddleware::class])
    ->prefix('admin')
    ->group(function () {
        require __DIR__.'/admin/core.php';
        require __DIR__.'/admin/audits.php';
        require __DIR__.'/admin/finance.php';
        require __DIR__.'/admin/members.php';
        require __DIR__.'/admin/milcom.php';
        require __DIR__.'/admin/applications.php';
        require __DIR__.'/admin/settings.php';
        require __DIR__.'/admin/mmr.php';
        require __DIR__.'/admin/customization.php';
    });
