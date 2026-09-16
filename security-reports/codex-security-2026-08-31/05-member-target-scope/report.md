## Executive Summary

In Nexus-AMS `v0.8.0-beta`, Mallory can use a legitimate administrator session with `view-members` to request `/admin/members/{nation}` for Alice's existing Nation record even when Alice is outside the alliances returned by `AllianceMembershipService`. The member index applies that alliance filter, but `MembersController::show()` authorizes only `view-members` and accepts the implicitly bound `Nation` object. The page then loads Alice's score history and other member data selected by Mallory's separate permissions. This is an object-level authorization failure across the managed-alliance boundary; it does not require session theft, a forged protocol message or a compromised Alice account.

The same unscoped Nation parameter is accepted by the member-inactivity-exception routes. Mallory additionally needs `manage-member-exceptions` to use that branch. The service validates the exception window and selected automation, but not Alice's alliance membership, so an active `DisableAccount` exception can be stored for an out-of-scope Nation. The scheduled `users:disable-inactive` command turns all active `DisableAccount` exceptions into a global list of protected nation IDs and excludes local users with those IDs from automatic disabling. This can suppress an intended account-disablement action for Alice's linked local user when the application contains one; it does not directly enable an account or prove an impact on every deployment.

The earliest affected release I could verify for the read branch is `v0.3.0-alpha`, where the index filtered `Nation` rows by `PW_ALLIANCE_ID` while `getNationStats(Nation $nation)` accepted any bound Nation. The read path remains present through the assessed `v0.8.0-beta`; the inactivity-exception routes and the global `DisableAccount` suppression branch were first present in `v0.8.0-beta` after commit `b8d47218`. I found no fixed release or inspected fix. I reviewed the pinned release, its earlier member-page source and the introducing inactivity-exception change directly; I did not execute a request or the disabling command.

## Background

The application has a defined managed-membership concept. In `app/Services/AllianceMembershipService.php:27-53`, `getAllianceIds()` returns the configured primary alliance together with enabled offshores, and `contains()` tests whether an alliance ID belongs to that set. The overview query in `app/Services/MemberStatsService.php:45-51` uses that service and excludes applicants and nations in vacation mode, so the normal member list presents a constrained population.

The administrative routes are behind authentication, verification, Discord verification, MFA and `AdminMiddleware` in `routes/web.php:21-27`. The member controller then applies `view-members`. That permission is intentionally broader than the category-specific permissions used for taxes, accounts, loans, grants, military data and timeline sources. A limited administrator may therefore be able to open a member page while still being unable to see some of its private histories; the finding concerns the missing Nation-membership check in addition to those existing permission checks.

The relevant route declarations in `routes/admin/members.php:8-17` are ordinary implicit model bindings:

```php
Route::get('/members', [AdminMembersController::class, 'index'])->name('admin.members');
Route::get('/members/{Nation}', [AdminMembersController::class, 'show'])->name('admin.members.show');
Route::post('/members/{nation}/inactivity-exceptions', [MemberInactivityExceptionController::class, 'store'])
    ->name('admin.members.inactivity-exceptions.store');
Route::put('/members/{nation}/inactivity-exceptions/{memberInactivityException}', [MemberInactivityExceptionController::class, 'update'])
    ->scopeBindings()
    ->name('admin.members.inactivity-exceptions.update');
```

The route-level `scopeBindings()` on update and delete ensures that an exception belongs to the supplied Nation, but it does not establish that the parent Nation belongs to the managed alliance. The store route has no parent scope beyond resolving a real Nation row.

## Vulnerability Details

The read path begins with a correct control that is not reused. `MemberStatsService::getOverviewData()` restricts the index to `whereIn('alliance_id', $this->membershipService->getAllianceIds())` at `app/Services/MemberStatsService.php:45-51`. A visitor can follow a listed member link, but the controller does not make the reverse assumption that every Nation ID supplied to the detail route is listed.

In `app/Http/Controllers/Admin/MembersController.php:41-67`, `show()` receives `Nation $nation`, checks only `view-members`, and immediately passes that object to the statistics service and the member timeline:

```php
public function show(
    MemberTimelineRequest $request,
    Nation $nation,
    MemberStatsService $service,
    MemberTimelineService $timelineService,
): View {
    $this->authorize('view-members');

    /** @var User $viewer */
    $viewer = $request->user();
    $viewData = $service->getNationStats($nation, $viewer);
    $canManageMemberExceptions = $viewer->can('manage-member-exceptions');
    $memberInactivityExceptions = $canManageMemberExceptions
        ? $nation->memberInactivityExceptions()
            ->with(['approver:id,name', 'lastReviewer:id,name', 'revokedBy:id,name'])
            ->orderByDesc('starts_at')
            ->get()
        : collect();
```

There is no call to `AllianceMembershipService::contains($nation->alliance_id)`, no `whereIn` on the bound Nation and no equivalent policy decision. In `app/Services/MemberStatsService.php:212-227,252-328`, the chosen ID becomes the key for `NationSignIn`, tax, grant, loan, account and resource-history queries. The returned view at `resources/views/admin/members/show.blade.php:16-140` renders the profile, score history and the data sets for which Mallory has category-specific permissions. `MemberTimelineService::forNation()` at `app/Services/Admin/MemberTimeline/MemberTimelineService.php:47-65` filters sources by permission, but likewise accepts the already unscoped Nation object.

The inactivity branch repeats the missing boundary at a write-capable entry point. `MemberInactivityExceptionController::store()` authorizes the model action and passes the route-bound Nation to `MemberInactivityExceptionService::create()` at `app/Http/Controllers/Admin/MemberInactivityExceptionController.php:20-31`. The policy's only checks are `view-members` and `manage-member-exceptions` at `app/Policies/MemberInactivityExceptionPolicy.php:47-51`. After locking the selected Nation, `MemberInactivityExceptionService::create()` writes its ID and the selected automations at `app/Services/MemberInactivityExceptionService.php:22-44`:

```php
Nation::query()->whereKey($nation->getKey())->lockForUpdate()->firstOrFail();
$this->assertNoOverlap((int) $nation->getKey(), $startsAt, $endsAt);

$exception = MemberInactivityException::query()->create([
    'nation_id' => $nation->getKey(),
    'category' => MemberInactivityExceptionCategory::from((string) $data['category']),
    'starts_at' => $startsAt,
    'ends_at' => $endsAt,
    'affected_automations' => $this->automations($data),
    'approved_by_user_id' => $approver->getKey(),
]);
```

The validation and overlap checks protect the exception's time and shape, not its owner. Update and revoke use the same parent Nation supplied by the route; nested binding prevents changing an exception through a different Nation, but no code in the controller, policy or service verifies that the parent is managed.

The downstream effect is specific to the newly added global account-disablement branch. `MemberInactivityExceptionEvaluator::nationIdsSuppressing()` at `app/Services/MemberInactivityExceptionEvaluator.php:61-73` scans every active exception, filters for the requested automation and returns every matching `nation_id`; it does not join or filter by `AllianceMembershipService`. `DisableInactiveUsers::handle()` at `app/Console/Commands/DisableInactiveUsers.php:39-66` then applies that list to the whole local `users` table:

```php
$protectedNationIds = $exceptionEvaluator->nationIdsSuppressing(
    MemberInactivityAutomation::DisableAccount,
    now(),
);

$disabledCount = User::query()
    ->where('disabled', false)
    ->when($protectedNationIds !== [], function ($query) use ($protectedNationIds): void {
        $query->where(function ($query) use ($protectedNationIds): void {
            $query->whereNull('nation_id')->orWhereNotIn('nation_id', $protectedNationIds);
        });
    })
```

Thus a manager can attach a `DisableAccount` exception to an out-of-alliance Nation, and the daily command will treat that ID as protected if a matching local User exists. This global branch was introduced with the inactivity-exception feature in `b8d47218` and is present in `v0.8.0-beta`. The separate `InactivityModeService` does initially fetch only managed alliance nations, so this report does not claim that an out-of-alliance exception suppresses every inactivity action; the demonstrated operational sink is the global account-disablement command.

The release comparison gives two related introduction points. In `v0.3.0-alpha`, `MemberStatsService::getOverviewData()` used `where('alliance_id', env("PW_ALLIANCE_ID"))`, while `MembersController::show(Nation $nation, ...)` passed any implicit Nation binding to `getNationStats()`. The exception model, policy, controller, evaluator and `DisableAccount` filtering do not exist in that tag; they first appear in `v0.8.0-beta`. The assessed commit is the `v0.8.0-beta` tag, and no later release or patch containing a membership guard was available for inspection.

## Exploitability Analysis

The narrow source-supported primitive is authenticated object selection: Mallory must already be an administrator accepted by the normal authentication, verification, Discord, MFA and `AdminMiddleware` checks, and she must have `view-members`. She supplies a valid Nation ID that she could obtain elsewhere or guess and reaches a detail page that should be limited to the managed alliance. With only `view-members`, the source still provides the Nation profile and score history; with additional category permissions, it queries the corresponding financial, military, application or audit records for the same out-of-scope Nation. The source review does not establish that every possible Nation attribute is rendered or that every deployment stores those related rows.

The second primitive requires the narrower `manage-member-exceptions` permission in addition to `view-members`. Mallory can choose a valid future window, category, reason and automation and submit it against an arbitrary Nation row. If she selects `DisableAccount`, the active row can enter the global protected-ID list used by the daily auto-disable command. The effect requires a local User whose `nation_id` equals Alice's Nation ID, the command to be enabled and the user to otherwise meet the inactivity criteria. It suppresses automatic disabling; it does not grant Mallory control of Alice's session, change Alice's Nation, or prove that manual disabling and other workflows are bypassed.

Several controls narrow the issue without closing it. A non-administrator is rejected by `AdminMiddleware`, a user without `view-members` is rejected by the controller and `MemberTimelineRequest`, and a user without `manage-member-exceptions` cannot create an exception under the policy. Existing category checks still gate many private histories. The list query demonstrates the expected same-domain behavior: managed members are returned through the index. The existing nested-binding test also demonstrates that an exception owned by Alice cannot be updated through a different Nation parent. None of these controls checks whether the selected parent Nation is inside the managed set.

There is an important policy ambiguity. If administrators are intentionally allowed to inspect all locally synchronized Nation rows, including former members and unrelated rows, then the direct detail behavior may be a product policy rather than a vulnerability. Likewise, the global account-disablement command may intentionally govern every local User rather than only managed alliance members. I have not assumed those broader policies. The finding is based on the observable distinction that the index is explicitly alliance-scoped, the routes and controller are named and permissioned as member administration, and the exception branch can affect a global automation without recording or checking the managed-membership boundary.

## Proof of Concept

No PoC artifact is included, and I did not send HTTP requests, inspect a live response or execute either inactivity command. The finding was established by comparing the pinned `v0.8.0-beta` source with `v0.3.0-alpha`, tracing the route-bound `Nation` through the controller and service queries, and tracing `DisableAccount` exceptions into the scheduled command. A safe runtime reproduction would require a disposable database containing Mallory, a managed Nation, Alice's out-of-alliance Nation and, for the automation branch, a linked inactive local User; executing it against a real tenant would disclose data and could change account state.

The expected negative controls for such a disposable reproduction are that Mallory's request for a managed Nation succeeds, the same `view-members` request for Alice's out-of-alliance Nation returns an authorization failure or a non-disclosing not-found response, and an exception POST for Alice's Nation is rejected without creating a row. For the automation branch, an existing out-of-scope `DisableAccount` row should not contribute to the protected list. These are test expectations, not observed outputs, and are included to define the regression boundary rather than to claim a successful run.

## Remediation

Apply one reusable managed-Nation authorization decision at every member entry point, then repeat it in the service layer that writes or exposes member-scoped state. The decision should use `AllianceMembershipService::contains($nation->alliance_id)` so the primary alliance and explicitly enabled offshores retain the same semantics as the existing index. Returning a non-disclosing 404 for an out-of-scope Nation avoids turning the route into a membership oracle; a policy-backed 403 is also valid if that is the application's established convention.

For the read path, guard `MembersController::show()` before `getNationStats()`, exception loading or timeline generation, or bind the route through a query that includes the managed-alliance predicate. The guard must cover every data source in `getNationStats()` and every timeline category, not just the visible member card. Keep the existing category-specific permission checks because membership and data-category authorization are separate inputs.

For inactivity exceptions, enforce the same parent-Nation check in `store`, `update` and `destroy`, and in `MemberInactivityExceptionService` so a future caller cannot bypass the controller. Keep the `scopeBindings()` parent/child check for update and delete. For existing exception rows, decide whether out-of-scope rows are invalid under the product policy and either expire/revoke them through an audited migration or explicitly preserve them under a separately documented global workflow.

If automatic account disabling is intended to be managed-alliance-only, constrain `nationIdsSuppressing(MemberInactivityAutomation::DisableAccount)` or its command caller to the same managed Nation IDs before applying the list to Users. If the command is intentionally global, split that global exception capability from the managed-member exception UI and permission, make the global scope explicit in its name and audit records, and do not use the member route as the way to create it. No fixed release or shipped remediation was inspected.

Add regression tests for an administrator with `view-members` opening a managed Nation and being rejected for an out-of-alliance Nation, while retaining the existing category-permission tests. Add create, update and revoke tests for both managed and out-of-scope parent Nations, including the existing cross-member nested-binding case. Add an automation test showing that an out-of-scope exception cannot suppress the intended account-disablement set, and an offshore-membership test showing that an enabled managed offshore remains allowed. The tests should assert both the response and the absence of an unintended database write.

## Summary

At the assessed `v0.8.0-beta`, a legitimate limited administrator with `view-members` can select any existing Nation ID in the member detail route because only the overview list applies the managed-alliance filter. Additional permissions determine which private histories are visible, but they do not restore object-level membership authorization. A manager with `view-members` and `manage-member-exceptions` can also create an inactivity exception for an out-of-alliance Nation; selecting `DisableAccount` can place that ID in the global automatic-disablement suppression list when a linked local User and enabled scheduled workflow make the condition relevant.

The earliest affected release I could verify is `v0.3.0-alpha` for the read branch. The exception and global suppression branch begins in `v0.8.0-beta` with `b8d47218`; no fixed release was identified. The most useful remaining validation is a disposable end-to-end test that confirms the intended product policy for former or unrelated Nation rows and checks the exact response and database effects, but the source already establishes the missing managed-membership guard.
