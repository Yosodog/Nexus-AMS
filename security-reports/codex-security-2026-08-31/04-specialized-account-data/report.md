## Executive Summary

In Nexus-AMS `v0.8.0-beta`, Mallory can use a legitimate administrator session that has `view-accounts` but lacks `view-dd` and `view-mmr` to open Alice's account-detail page. The route and controller authorize only `view-accounts`; the controller then queries Alice's direct-deposit logs and MMR Assistant purchases and the view renders both datasets. This crosses the separate direct-deposit and MMR confidentiality boundary without requiring session theft, a protocol weakness, or write access. The demonstrated impact is read-only disclosure of account-specific payout amounts, delivered resources, purchases, prices, dates, and nation identifiers.

The earliest affected release I could verify is `v0.7.0-alpha`; the issue remains in the pinned `v0.8.0-beta` source, and no fixed release was verified. The verified affected release span is therefore `v0.7.0-alpha` through `v0.8.0-beta`; intervening patch releases and branch tips were not sampled exhaustively. The feature was introduced by `0ec44a81` and the later `cac804cd` change gated the dashboard copy only, leaving the detail page unchanged.

I reviewed the pinned release source, the introducing and later gating changes, the permission definitions, and the existing authorization tests directly. I did not execute an HTTP request or build a runtime reproducer, so the response described below is expected behavior derived from the source rather than an observed capture.

## Background

The admin account workflow has a broad `view-accounts` permission and two narrower permissions: `view-dd` for direct-deposit information and `view-mmr` for MMR Assistant purchase information. The permissions are distinct in the pinned release:

```php
// config/permissions.php:13,39-44 (v0.8.0-beta)
'view-accounts',
'view-dd',
'manage-dd',
'view-growth-circles',
'manage-growth-circles',
'manage-mmr',
'view-mmr',
```

The account-detail endpoint is an authenticated admin route. Its route is registered as `admin.accounts.view`, and the admin route group supplies the normal authentication, verification, MFA, and administrator middleware before the controller is entered:

```php
// routes/admin/finance.php:20-22 (v0.8.0-beta)
// Account
Route::get('/accounts', [AccountController::class, 'dashboard'])->name('admin.accounts.dashboard');
Route::get('/accounts/{accounts}', [AccountController::class, 'view'])->name('admin.accounts.view');
```

The relevant attacker is therefore a legitimate administrator who has been deliberately granted account-view access but not the specialized read permissions. Alice is the owner of the account whose records are displayed. The intended behavior is that the account page may show ordinary account information to Mallory while withholding the specialized DD and MMR records unless the corresponding permission is present.

## Vulnerability Details

Mallory supplies Alice's account identifier as the `{accounts}` route parameter in a normal `GET /admin/accounts/{accounts}` request. Laravel resolves it to the `Account $accounts` argument. At the pinned release, `AccountController::view` checks only the broad permission and immediately loads the two specialized collections:

```php
// app/Http/Controllers/Admin/AccountController.php:169-177 (v0.8.0-beta)
public function view(Account $accounts)
{
    $this->authorize('view-accounts');

    $accounts->load('nation')
        ->load('user');

    $transactions = AccountService::getRelatedTransactions($accounts, 500);
    $manualTransactions = AccountService::getRelatedManualTransactions($accounts, 500);
```

```php
// app/Http/Controllers/Admin/AccountController.php:188-198 (v0.8.0-beta)
    $directDepositLogs = DirectDepositLog::query()
        ->where('account_id', $accounts->id)
        ->latest('created_at')
        ->paginate(10, ['*'], 'dd_page')
        ->withQueryString()
        ->fragment('direct-deposit-logs');
    $mmrPurchases = MMRAssistantPurchase::where('account_id', $accounts->id)
        ->latest('created_at')
        ->paginate(10, ['*'], 'mmr_page')
        ->withQueryString()
        ->fragment('mmr-assistant');
```

The decisive boundary is the first line: `view-accounts` is the only authorization check in this method. There is no `view-dd` check before the `DirectDepositLog` query and no `view-mmr` check before the `MMRAssistantPurchase` query. The queries are also keyed directly to Alice's account ID, so the data is prepared for the exact account Mallory requested.

The returned view receives both collections unconditionally:

```php
// app/Http/Controllers/Admin/AccountController.php:200-207 (v0.8.0-beta)
return view('admin.accounts.view', [
    'account' => $accounts,
    'transactions' => $transactions,
    'manualTransactions' => $manualTransactions,
    'stuckTransactions' => $stuckTransactions,
    'directDepositLogs' => $directDepositLogs,
    'mmrPurchases' => $mmrPurchases,
]);
```

The Blade template then displays the direct-deposit rows, including the timestamp, nation ID, cash paid, and delivered resources, followed by MMR rows containing the timestamp, total spent, purchased resources, and price-per-unit values:

```blade
{{-- resources/views/admin/accounts/view.blade.php:287-305 (v0.8.0-beta) --}}
    @forelse($directDepositLogs as $log)
        @php
            $deliveredResources = collect(PWHelperService::resources(false))
                ->filter(fn ($res) => (float) $log->$res > 0)
                ->mapWithKeys(fn ($res) => [$res => $log->$res]);
        @endphp
        <td>{{ $log->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
        <td>
            <a href="https://politicsandwar.com/nation/id={{ $log->nation_id }}" target="_blank" class="link link-primary">
                Nation #{{ $log->nation_id }}
            </a>
        </td>
        <td class="text-right">${{ number_format((float) $log->money, 2) }}</td>
        <td>
            @if($deliveredResources->isNotEmpty())
                <div class="flex flex-wrap gap-1">
                    @foreach($deliveredResources as $resource => $amount)
                        <x-badge  value="{{ ucfirst($resource) }}: {{ number_format((float) $amount, 2) }}" class="badge-ghost badge-sm" />
                    @endforeach
                </div>
            @else
                <span class="nexus-text-muted">Money only</span>
            @endif
        </td>
    @empty
        <tr>
            <td colspan="4" class="text-center nexus-text-muted py-4">No direct deposit activity for this account.</td>
        </tr>
    @endforelse
```

```blade
{{-- resources/views/admin/accounts/view.blade.php:350-371 (v0.8.0-beta) --}}
    @forelse($mmrPurchases as $purchase)
        @php
            $purchasedResources = collect(PWHelperService::resources(false))
                ->filter(fn ($res) => (float) $purchase->$res > 0)
                ->mapWithKeys(fn ($res) => [$res => [
                    'qty' => $purchase->$res,
                    'ppu' => $purchase->getAttribute("{$res}_ppu"),
                ]]);
        @endphp
        <td>{{ $purchase->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
        <td class="text-right">${{ number_format((float) $purchase->total_spent, 2) }}</td>
        <td>
            @if($purchasedResources->isNotEmpty())
                <div class="flex flex-wrap gap-1">
                    @foreach($purchasedResources as $resource => $data)
                        <x-badge class="badge-ghost badge-sm">
                            {{ ucfirst($resource) }}: {{ number_format((float) $data['qty'], 2) }}
                            @if($data['ppu'])
                                <span class="nexus-text-muted"> @ ${{ number_format((float) $data['ppu'], 2) }}</span>
                            @endif
                        </x-badge>
                    @endforeach
                </div>
            @else
                <span class="nexus-text-muted">No resources purchased</span>
            @endif
        </td>
    @empty
        <tr>
            <td colspan="3" class="text-center nexus-text-muted py-4">No MMR Assistant purchases for this account.</td>
        </tr>
    @endforelse
```

This behavior entered the project in `0ec44a81` (“Add direct deposit logs and MMR purchases to account view”), which added the two controller queries and their tables. Later, `cac804cd` (“Gate account dashboard DD and MMR data”) added `view-dd` and `view-mmr` checks around the dashboard's specialized queries and added `AdminAccountSpecializedDataAuthorizationTest`. That change is useful negative-control evidence: the dashboard now tests that a `view-accounts`-only administrator does not query or see these tables, but the diff does not add equivalent checks to `AccountController::view` or `admin.accounts.view`. The detail-page path therefore retains the introduced behavior in `v0.8.0-beta`.

## Exploitability Analysis

The narrow primitive is a read-only authorization bypass between `view-accounts` and the two dedicated specialized-data permissions. Mallory must already be an authenticated, verified administrator who has `view-accounts` and can reach the admin account route. She does not need `manage-dd`, `manage-mmr`, account modification rights, another user's session, or control of a network protocol. If Alice's account has direct-deposit logs or MMR purchases, the controller selects those records by Alice's account ID and the template places their values in Mallory's response.

The strongest supported impact is disclosure of financial and operational history. The source does not establish that Mallory can change balances, create deposits, make MMR purchases, impersonate Alice, execute code, or cross an unrelated tenant boundary. It also does not establish that every account has records; the leak is conditional on the requested account containing the specialized rows.

The dashboard authorization tests provide a meaningful negative control. At the pinned release, `AdminAccountSpecializedDataAuthorizationTest::test_account_viewer_does_not_query_or_see_direct_deposit_or_mmr_data` creates an administrator with only `view-accounts`, requests `admin.accounts.dashboard`, and asserts both that the specialized labels are absent and that the relevant tables are not queried. The companion tests grant `view-dd` or `view-mmr` separately and assert that only the corresponding dashboard data is queried and rendered. Those controls rule out the explanation that the dedicated permissions are intentionally redundant; they instead show that the intended policy is permission-specific. They do not cover `admin.accounts.view`, which is the missing negative control.

## Proof of Concept

No PoC artifact is included, and no HTTP request was executed. The following is the expected source-confirmed reproduction, suitable for a disposable test database containing Alice's account and at least one row in each specialized table:

1. Create or use Mallory's verified administrator account with `view-accounts` and without `view-dd` and `view-mmr`.
2. Authenticate through the application's ordinary admin login and MFA flow.
3. Request the named route `admin.accounts.view` with Alice's account ID, for example `GET /admin/accounts/{alice-account-id}`.
4. The expected secure result is that the account page omits the DD and MMR sections and does not query either specialized table. In the pinned source, the controller instead authorizes `view-accounts`, executes both account-filtered queries, and passes both collections to the template; the expected rendered result therefore contains the “Direct Deposit Logs” and “MMR Assistant Purchases” sections whenever Alice has rows.

This reproduction is read-only, but it should be performed only against disposable test data. The existing dashboard test can be adapted to capture database queries and assert the detail route's response; its current assertions are not evidence that the detail route is protected. Because the source review was not accompanied by an HTTP run, no response body, query trace, or successful runtime output is claimed here.

## Remediation

Enforce the specialized permissions in `AccountController::view` before either query runs, and keep the Blade checks as defense in depth. Compute `canViewDirectDeposit` from `view-dd` and `canViewMmr` from `view-mmr` for the current authenticated administrator. Only execute the corresponding `DirectDepositLog` or `MMRAssistantPurchase` query when its flag is true; otherwise pass `null` or an empty paginator-compatible value that the view will not iterate. Wrap the two cards and their pagination controls in the same permission-specific conditions. Do not use `manage-dd` or `manage-mmr` as an accidental substitute for the dedicated read permissions.

The dashboard's existing pattern is a source-compatible guide: it computes the two flags, conditionally performs each query, and passes the flags to the view. Apply that pattern to `view()` as well, or centralize the policy in a reusable authorization method so every account-detail entry point receives the same decision. The controller check is the security control; view guards alone are insufficient because the current controller already queries the data before rendering.

Add regression coverage for the actual `admin.accounts.view` route with an account that has both kinds of records:

- A `view-accounts`-only administrator must receive the ordinary account page without either specialized section, and the request must issue no query against `direct_deposit_logs` or `mmr_assistant_purchases`.
- An administrator with `view-accounts` and `view-dd` may see DD records but must not query or see MMR records.
- An administrator with `view-accounts` and `view-mmr` may see MMR records but must not query or see DD records.
- An administrator with both specialized permissions may see both datasets.

These tests should use Alice's seeded records and Mallory's real authorization state, rather than checking only whether a label is hidden in an otherwise data-bearing response.

## Summary

In the verified `v0.7.0-alpha` through `v0.8.0-beta` release span, an authenticated administrator with `view-accounts` but without `view-dd` or `view-mmr` can open Alice's account-detail route and is expected to receive Alice's direct-deposit logs and MMR Assistant purchase history. The controller's sole check is `view-accounts`, and both specialized queries and both rendered sections follow unconditionally. The impact is medium-severity, read-only disclosure of account-specific finance data; no modification, code execution, session theft, or protocol compromise was demonstrated.

The most useful remaining validation is a focused feature test for `admin.accounts.view` that captures queries and checks the three permission combinations. No fixed release was verified; the dashboard gating change `cac804cd` should not be treated as remediation for this detail-page path.
