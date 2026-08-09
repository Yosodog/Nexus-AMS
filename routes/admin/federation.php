<?php

use App\Http\Controllers\Admin\FederationCapabilityController;
use App\Http\Controllers\Admin\FederationCoalitionController;
use App\Http\Controllers\Admin\FederationController;
use App\Http\Controllers\Admin\FederationIdentityController;
use App\Http\Controllers\Admin\FederationLinkController;
use App\Http\Controllers\Admin\FederationPublicationController;
use App\Http\Controllers\Admin\FederationReceivedPlanController;
use Illuminate\Support\Facades\Route;

Route::get('/federation', [FederationController::class, 'index'])->name('admin.federation.index');

Route::post('/federation/identity/enable', [FederationIdentityController::class, 'enable'])
    ->name('admin.federation.identity.enable');
Route::post('/federation/identity/disable', [FederationIdentityController::class, 'disable'])
    ->name('admin.federation.identity.disable');
Route::post('/federation/identity/rotate', [FederationIdentityController::class, 'rotate'])
    ->name('admin.federation.identity.rotate');
Route::post('/federation/identity/transfer-ownership', [FederationIdentityController::class, 'transferOwnership'])
    ->name('admin.federation.identity.transfer-ownership');
Route::post('/federation/identity/keys/{key}/activate', [FederationIdentityController::class, 'activate'])
    ->name('admin.federation.identity.keys.activate');
Route::post('/federation/identity/keys/{key}/compromise', [FederationIdentityController::class, 'compromise'])
    ->name('admin.federation.identity.keys.compromise');

Route::post('/federation/links/discover', [FederationLinkController::class, 'discover'])
    ->name('admin.federation.links.discover');
Route::post('/federation/links', [FederationLinkController::class, 'store'])
    ->name('admin.federation.links.store');
Route::post('/federation/link-invitations/{invitation}/approve', [FederationLinkController::class, 'approve'])
    ->name('admin.federation.links.approve');
Route::post('/federation/link-invitations/{invitation}/activate', [FederationLinkController::class, 'activate'])
    ->name('admin.federation.links.activate');
Route::post('/federation/links/{link}/suspend', [FederationLinkController::class, 'suspend'])
    ->name('admin.federation.links.suspend');
Route::post('/federation/links/{link}/revoke', [FederationLinkController::class, 'revoke'])
    ->name('admin.federation.links.revoke');
Route::post('/federation/links/{link}/endpoint-change', [FederationLinkController::class, 'proposeEndpoint'])
    ->name('admin.federation.links.endpoint.propose');
Route::post('/federation/endpoint-changes/{proposal}/approve', [FederationLinkController::class, 'approveEndpoint'])
    ->name('admin.federation.links.endpoint.approve');
Route::post('/federation/endpoint-changes/{proposal}/reject', [FederationLinkController::class, 'rejectEndpoint'])
    ->name('admin.federation.links.endpoint.reject');
Route::post('/federation/endpoint-changes/{proposal}/activate', [FederationLinkController::class, 'activateEndpoint'])
    ->name('admin.federation.links.endpoint.activate');
Route::post('/federation/links/{link}/peer-keys/{key}/reapprove', [FederationLinkController::class, 'reapproveKey'])
    ->name('admin.federation.links.keys.reapprove');
Route::post('/federation/links/{link}/stage-recovery-key', [FederationLinkController::class, 'stageRecoveryKey'])
    ->name('admin.federation.links.keys.stage-recovery');

Route::post('/federation/coalitions', [FederationCoalitionController::class, 'store'])
    ->name('admin.federation.coalitions.store');
Route::post('/federation/coalitions/{coalition}/invite', [FederationCoalitionController::class, 'invite'])
    ->name('admin.federation.coalitions.invite');
Route::post('/federation/coalition-invitations/{invitation}/accept', [FederationCoalitionController::class, 'accept'])
    ->name('admin.federation.coalitions.accept');
Route::post(
    '/federation/coalitions/{coalition}/memberships/{membership}/remove',
    [FederationCoalitionController::class, 'remove']
)->name('admin.federation.coalitions.memberships.remove');
Route::post('/federation/coalitions/{coalition}/dissolve', [FederationCoalitionController::class, 'dissolve'])
    ->name('admin.federation.coalitions.dissolve');
Route::post('/federation/coalitions/{coalition}/proposals', [FederationCoalitionController::class, 'propose'])
    ->name('admin.federation.coalitions.proposals.store');
Route::post('/federation/coalition-proposals/{proposal}/approve', [FederationCoalitionController::class, 'approveProposal'])
    ->name('admin.federation.coalitions.proposals.approve');
Route::post('/federation/coalition-proposals/{proposal}/reject', [FederationCoalitionController::class, 'rejectProposal'])
    ->name('admin.federation.coalitions.proposals.reject');
Route::post('/federation/coalition-proposals/{proposal}/accept-transfer', [FederationCoalitionController::class, 'acceptTransfer'])
    ->name('admin.federation.coalitions.transfers.accept');
Route::post('/federation/coalition-proposals/{proposal}/approve-transfer', [FederationCoalitionController::class, 'approveTransfer'])
    ->name('admin.federation.coalitions.transfers.approve');
Route::post('/federation/coalitions/{coalition}/capabilities', [FederationCapabilityController::class, 'store'])
    ->name('admin.federation.capabilities.store');

Route::post('/federation/publications/preview', [FederationPublicationController::class, 'preview'])
    ->name('admin.federation.publications.preview');
Route::get('/federation/publication-versions/{version}/preview', [FederationPublicationController::class, 'showPreview'])
    ->name('admin.federation.publications.preview.show');
Route::post('/federation/publication-versions/{version}/publish', [FederationPublicationController::class, 'publish'])
    ->name('admin.federation.publications.publish');
Route::post(
    '/federation/publications/{publication}/revoke-recipient',
    [FederationPublicationController::class, 'revokeRecipient']
)->name('admin.federation.publications.revoke-recipient');
Route::post('/federation/publications/{publication}/revoke', [FederationPublicationController::class, 'revokeAll'])
    ->name('admin.federation.publications.revoke');

Route::post('/federation/received-versions/{version}/accept', [FederationReceivedPlanController::class, 'accept'])
    ->name('admin.federation.received.accept');
Route::post('/federation/received-versions/{version}/reject', [FederationReceivedPlanController::class, 'reject'])
    ->name('admin.federation.received.reject');
Route::post('/federation/received-versions/{version}/retry-import', [FederationReceivedPlanController::class, 'retryImport'])
    ->name('admin.federation.received.retry-import');
Route::post('/federation/operations/{operation}/detach', [FederationReceivedPlanController::class, 'detach'])
    ->name('admin.federation.operations.detach');
Route::post('/federation/operations/{operation}/retire', [FederationReceivedPlanController::class, 'retire'])
    ->name('admin.federation.operations.retire');
