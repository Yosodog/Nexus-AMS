<?php

use App\Http\Controllers\API\FederationEnvelopeController;
use App\Http\Controllers\API\FederationHandshakeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/federation')->group(function (): void {
    Route::post('handshakes', FederationHandshakeController::class)
        ->middleware('throttle:federation-handshakes')
        ->name('api.federation.handshakes');
    Route::post('envelopes', FederationEnvelopeController::class)
        ->middleware('throttle:federation-ingress')
        ->name('api.federation.envelopes');
});
