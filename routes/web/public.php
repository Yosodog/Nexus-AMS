<?php

use App\Http\Controllers\ApplyPageController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\Testing\BrowserTestController;
use Illuminate\Support\Facades\Route;

Route::get('/robots.txt', [SeoController::class, 'robots'])->name('seo.robots');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('seo.sitemap');

Route::get('/', HomeController::class)->name('home');

Route::get('/apply', [ApplyPageController::class, 'show'])->name('apply.show');
Route::get('/apply/start', [ApplyPageController::class, 'start'])
    ->middleware('throttle:60,1')
    ->name('apply.start');
Route::get('/apply/member-registration', [ApplyPageController::class, 'memberRegistration'])
    ->middleware('throttle:60,1')
    ->name('apply.member-registration');

if (app()->environment('testing')) {
    Route::get('/_browser/login/{persona}', [BrowserTestController::class, 'login'])
        ->whereIn('persona', ['admin', 'limited', 'member'])
        ->name('browser.login');
}
