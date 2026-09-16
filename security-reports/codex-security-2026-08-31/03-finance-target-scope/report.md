## Executive Summary

In `v0.8.0-beta`, Mallory can use a legitimate administrator session with the `manage-accounts` permission to submit an account or transaction ID belonging to Alice's nation outside the configured alliance umbrella. The finance controllers load that record by ID and can adjust its local resource balances, freeze or unfreeze it, refund or deny a withdrawal, or approve an eligible withdrawal for dispatch. A user with `view-accounts` can also request an arbitrary account detail page by ID. Mallory reuses her own authenticated session; this is not session theft, a transport-protocol flaw, or a claim that she can execute code on the host.

The application presents the intended boundary in the account dashboard: it builds the configured primary alliance plus enabled offshores and filters the account list by the nation's `alliance_id`. The direct action paths do not repeat that check. I rate the resulting authorization scope failure medium because Mallory must already be a finance administrator, while the source establishes a concrete cross-alliance balance and withdrawal-operation primitive rather than arbitrary code execution or compromise of an external bank account.

The earliest release in which I could directly verify this inconsistent boundary is `v0.5.0-alpha`, where the dashboard filter was introduced while the ID-based account operations remained. I verified the same pattern in `v0.7.0-alpha` and the assessed `v0.8.0-beta`; no fixed release or inspected fix was identified. The manual adjustment implementation existed earlier, but the application-level alliance boundary was not directly established in the earlier account dashboard snapshot. I reviewed the pinned release, relevant tags, and introducing history directly; I did not execute requests or a proof of concept.

## Background

The affected surface is the Laravel admin finance area. The admin route group requires authentication, a verified user, Discord verification when that feature is enabled, MFA when the configured policy requires it, and `AdminMiddleware`; the latter checks `is_admin`. The individual finance operations then authorize the narrower `manage-accounts` or `view-accounts` permission. These are real prerequisites, not bypasses supplied by this finding.

`AllianceMembershipService` defines the application's managed umbrella as the configured primary alliance merged with enabled offshore alliance IDs:

```php
$allianceIds = $membership->getAllianceIds();

$accounts = Account::with('user')
    ->whereHas('nation', function ($q) use ($allianceIds) {
        $q->whereIn('alliance_id', $allianceIds);
    })
    ->orderBy('nation_id')
    ->get();
```

This is the source behavior in `app/Http/Controllers/Admin/AccountController.php:60-68`, using `app/Services/AllianceMembershipService.php:27-33,129-147` in `v0.8.0-beta`. Bob's account is expected to appear when Bob's nation belongs to that set; Alice's account outside it is expected not to appear in the account list. The report assumes the dashboard filter represents the intended authorization boundary. If the product intentionally gives every finance administrator authority over every locally stored historical account and transaction, this finding would not apply, but that interpretation conflicts with the explicit dashboard scoping.

## Vulnerability Details

The routes in `routes/admin/finance.php:20-28,40-48,113-118` expose account detail, balance adjustment, freeze/unfreeze, refund, withdrawal approval, denial, and reconciliation operations. `routes/web.php:21-33` includes that route file below the common admin middleware. The route parameters are ordinary `Account` or `Transaction` model bindings, and none of these routes adds an alliance predicate.

The dashboard boundary is not carried into direct account lookup. In `AccountController::view`, `view-accounts` is checked and the bound account is loaded together with its nation, user, related transactions, manual transactions, pending withdrawals, direct-deposit logs, and MMR purchases. The method does not verify that the account's nation is in `AllianceMembershipService::getAllianceIds()` (`app/Http/Controllers/Admin/AccountController.php:169-207`). A user who has `view-accounts` and knows an account ID can therefore reach the read variant even without `manage-accounts`.

The write path is more direct:

```php
public function adjustBalance(Request $request)
{
    $this->authorize('manage-accounts');
```

The same method later selects the target as follows:

```php
$account = AccountService::getAccountById($request->input('accountId'));
```

These exact excerpts are from `AccountController::adjustBalance` at `app/Http/Controllers/Admin/AccountController.php:607-609,627`. The method performs the self-action check and then passes the loaded account to `AccountService::adjustAccountBalance` (`:629-643`), but it never checks the account's nation against the managed alliance IDs. `freeze` and `unfreeze` use the same `manage-accounts` authorization and pass the route-bound account directly to `AccountService::setFrozen` (`app/Http/Controllers/Admin/AccountController.php:213-246`). The target is therefore selected by an ID rather than by the scoped account collection.

`AccountService::getAccountById` does not add another policy boundary:

```php
public static function getAccountById(int $id): Account
{
    return Account::where('id', $id)
        ->lockForUpdate()
        ->firstOrFail();
}
```

At `app/Services/AccountService.php:293-297`, this locks whichever local account has the supplied primary key. `adjustAccountBalance` then locks that same row, adds each submitted resource, saves it, and creates a `ManualTransaction` for the account (`app/Services/AccountService.php:599-660`). The lock and audit record protect consistency and traceability; neither establishes that the target belongs to the configured alliance.

The withdrawal dashboard has the same missing predicate. `AccountController::dashboard` filters the account table, but its recent transaction, pending-withdrawal, and reconciliation queries are not constrained by `alliance_id` (`app/Http/Controllers/Admin/AccountController.php:82-126`). In particular, a finance manager can receive pending withdrawal records from outside the managed set through the dashboard that is meant to support the approval operation.

The approval controller checks the ordinary withdrawal state machine and self-approval rule, but not membership:

```php
Gate::authorize('manage-accounts');

$this->selfApprovalGuard->ensureNotSelf(
    requestNationId: $transaction->nation_id,
    context: 'approve your own withdrawal request'
);
```

In `WithdrawalController::approve` (`app/Http/Controllers/Admin/WithdrawalController.php:116-160`), the transaction is reloaded with `lockForUpdate`, rejected if it is no longer pending, marked approved, and passed to `AccountService::dispatchWithdrawal`. `deny` performs the analogous `manage-accounts` and self-approval checks, reloads the transaction by ID, returns its resources to the source account, and marks it denied (`app/Http/Controllers/Admin/WithdrawalController.php:194-245`). `reconcile` additionally requires `view-diagnostic-info` and has evidence/state checks, but its locked transaction can still be selected by ID without a membership predicate (`:302-444`). Refund and unstuck-refund actions likewise use a transaction ID and local state checks without an alliance check (`AccountController::refundTransaction` and `unstuckAndRefundTransaction`).

The approval sink is concrete even though the external bank outcome is deployment-dependent:

```php
$fromAccount ??= $transaction->fromAccount;
$bank->receiver = $transaction->nation_id;

foreach (PWHelperService::resources() as $res) {
    $bank->$res = $transaction->$res;
}

$bank->send($transaction);
```

This is `AccountService::dispatchWithdrawal` at `app/Services/AccountService.php:516-533`. Thus, for a pending withdrawal transaction owned by Alice's outside-alliance nation, Mallory can reach the existing approval transition and the application call that dispatches the transaction. The source does not prove that a particular external bank would accept the request, so the demonstrated primitive is unauthorized local approval and dispatch, not a guaranteed external transfer.

The history explains why the inconsistency is now visible. The manual transaction and balance-adjustment path was introduced by `84c5d6e3` and is present in the older release line. `v0.4.0-alpha` still built the account table without an alliance predicate. Change `597c5b99` introduced `AllianceMembershipService` filtering for that table, and `v0.5.0-alpha` is the earliest release snapshot I checked where the filtered list coexists with the unscoped direct account ID operation. Withdrawal controls were introduced in the same release lineage by `345e95a8`; their later approval, reconciliation, locking, and self-approval hardening appears in `v0.7.0-alpha` and `v0.8.0-beta`, but none of the inspected versions adds the missing target-scope check. I also checked `v0.4.0-alpha`, `v0.5.0-alpha`, `v0.7.0-alpha`, and `v0.8.0-beta`; no `v0.6` tag is present, and untagged intermediate snapshots were not individually sampled.

## Exploitability Analysis

The narrow source-supported attack is an authenticated horizontal authorization failure between finance records. Mallory needs a valid admin account that passes the route middleware and the `manage-accounts` permission for write actions, plus a target account or transaction ID. Alice need not have an account on Mallory's nation; the important condition is that Alice's locally stored account or withdrawal transaction points to a nation whose alliance is outside the IDs returned by `AllianceMembershipService`.

For an account adjustment, Mallory controls the `accountId` and the resource delta. The service locks Alice's row, changes the local balance, and writes a manual transaction under Mallory's admin ID. For freeze or unfreeze, the route-bound account is passed directly to the state-changing service. For a withdrawal, Mallory controls the transaction identifier in the route; an eligible pending transaction passes the existing state checks, is marked approved or denied, and in the approval case is passed to the bank-dispatch service. The dashboard's unscoped pending-withdrawal query can disclose useful transaction IDs and target details to a finance manager, although this review does not claim that every deployment exposes IDs in the same way.

Several controls remain effective and narrow the result:

- `SelfApprovalGuard::ensureNotSelf` rejects actions involving Mallory's own nation or user unless she has the separate `bypass-self-restrictions` permission (`app/Services/SelfApprovalGuard.php:15-33`). The finding concerns Alice's outside-alliance record, not self-approval.
- Transaction approval, denial, refund, and reconciliation use locks and state predicates for pending, approved, denied, sent, processing, and bank-reconciliation states. Those checks prevent many duplicate or unsafe transitions but do not ask whether the nation belongs to the managed alliance.
- The dashboard account query is a source-level positive control for the intended policy: membership IDs are explicitly applied there. The missing negative control is the corresponding out-of-scope check on every direct read and write entry point.
- I did not execute an in-scope request, an out-of-scope request, or a configuration-based mitigation. There is no observed HTTP response, database result, bank response, or runtime reproduction in this report.

The impact is therefore bounded to local account and transaction records plus the application dispatch path. The source does not establish credential theft, arbitrary code execution, a guaranteed bank transfer, or reliable compromise of a separate alliance's external systems. A deployment that intentionally authorizes finance staff over all historical local records may have no vulnerability here; the explicit account-list filter is the evidence against that benign explanation.

## Proof of Concept

No PoC artifact was created, and no request was sent. The following is an unexecuted expected reproduction based solely on the inspected `v0.8.0-beta` source:

1. Configure a primary alliance and, if applicable, enabled offshores so Alice's nation is outside the IDs returned by `AllianceMembershipService`. Give Mallory an authenticated, verified admin account with `manage-accounts`; for the read variant, give her `view-accounts`.
2. Obtain an existing local account ID for Alice's nation. Mallory submits the finance route with the request body field `accountId` set to that ID and a valid resource delta plus the required note. The expected source-level result is that `getAccountById` locks Alice's account and `adjustAccountBalance` applies the delta and records a manual transaction. This result was not observed at runtime.
3. For the withdrawal variant, obtain an eligible pending transaction ID for Alice's nation and submit the `admin.withdrawals.approve` route as Mallory. The expected source-level result is that the transaction passes its pending/self/state checks, is marked approved, and is passed to `dispatchWithdrawal`; the external bank response is unknown and was not tested.
4. A read-only check would request `admin.accounts.view` with Alice's account ID as Mallory with `view-accounts`. The expected result is that the account detail loader returns the bound account and related records because it does not apply the dashboard membership predicate. This was also not executed.

These steps are an expected, unexecuted reproduction, not a successful exploit log. They require a disposable local database and a stubbed bank service for safe runtime validation. No data was changed, no bank service was contacted, and no cleanup was necessary during this source-only review.

## Remediation

Treat alliance membership as an authorization condition, not only a dashboard display filter. Add a single finance target-scope service or policy that resolves the configured IDs through `AllianceMembershipService` and checks an account's related nation. Use it for `view`, `adjustBalance`, `freeze`, `unfreeze`, refunds, and every other account operation. For transactions, check the transaction's nation and source account relation under the same policy, and reject inconsistent `nation_id`/`from_account_id` combinations rather than relying on whichever direct ID was supplied.

Apply the check on the locked row inside each state-changing transaction as well as before the operation, so a target whose membership changes between lookup and write cannot bypass the policy. Scope the dashboard's recent transactions, pending withdrawals, and reconciliation queues through the same service. Preserve the existing self-approval, bank-reconciliation, evidence, and state-machine checks; they address different risks and should remain defense in depth.

A proposed implementation should return a not-found or forbidden response consistently for an out-of-scope target, avoid leaking whether arbitrary account IDs exist, and include the managed primary alliance and enabled offshores exactly as the dashboard does. This is a proposed remediation, not an inspected shipped fix; no fixed release was identified.

Regression coverage should include an in-scope account and withdrawal that remain allowed, an out-of-scope account rejected for detail and adjustment, out-of-scope freeze/unfreeze and refund paths, out-of-scope pending approval/denial/reconciliation, view-only versus manage permissions, enabled-offshore membership, a target whose membership changes before the locked write, and a transaction whose nation and source account do not agree. Existing self-approval and terminal-state tests should continue to pass.

## Summary

In `v0.5.0-alpha` through the inspected `v0.8.0-beta`, the application visibly scopes its finance account list to the configured alliance umbrella but lets direct admin finance operations select local accounts and withdrawal transactions by unscoped ID. A finance administrator with `manage-accounts` can consequently alter an outside-alliance account's local balance or frozen state and can operate on an eligible outside-alliance withdrawal, including reaching the bank-dispatch call; `view-accounts` also exposes an unscoped account-detail read variant. Self-approval and transaction-state controls remain effective, and no stronger external impact was demonstrated. No fix release was verified.
