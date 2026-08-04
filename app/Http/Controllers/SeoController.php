<?php

namespace App\Http\Controllers;

use App\Services\SeoService;
use Illuminate\Http\Response;

class SeoController extends Controller
{
    public function robots(SeoService $seoService): Response
    {
        return response($seoService->robotsContent())
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function sitemap(SeoService $seoService): Response
    {
        abort_unless($seoService->isGlobalIndexingEnabled(), 404);

        return response()
            ->view('seo.sitemap', ['urls' => $seoService->sitemapUrls()])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
