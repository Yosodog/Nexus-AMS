## Executive Summary

In Nexus-AMS v0.8.0-beta, Mallory can use a limited administrator account that has the `edit-users` permission to change the `nation_id` of a different user account to Alice's nation. The update endpoint validates only that the submitted value names an existing nation; it does not require a nation-transfer permission or check that Mallory is authorized to change that user-to-nation binding. This is not session theft, a broken Discord verification flow, or a way for Mallory to edit her own account: the target must be a separate, manageable login that Mallory can use after the change.

Once that login is authenticated, the member finance code treats its `User::nation_id` as the ownership boundary. The target login therefore resolves Alice's accounts and can reach the account dashboard, account details, statements, and transfer paths that are guarded by that value. The narrow demonstrated primitive is unauthorized reassignment of a login's nation binding, with downstream access or finance actions possible when Alice's accounts exist and the target login satisfies the normal verification, Discord-link, and configured MFA requirements. I assessed the source at v0.8.0-beta and traced its history; I did not execute the request or observe a runtime response, and no fixed release or inspected fix was available.

The earliest released version I could verify as affected is v0.3.0-alpha, where the user-editing feature that introduced this assignment is contained. The same behaviour is present in the inspected v0.8.0-beta source. The repository has no verified fixed release, so the complete affected range cannot be closed beyond those verified points; intermediate tags and patch builds were not each independently executed or reviewed.

## Background

The application associates a web login with a Politics & War nation through `users.nation_id`. `User::accounts()` then looks up accounts by that same nation key:

```php
public function accounts()
{
    return $this->hasMany(Account::class, 'nation_id', 'nation_id');
}
```

This relationship is in `app/Models/User.php` in v0.8.0-beta. The user migration adds `nation_id` as an unsigned integer and a hosted-runtime index, but does not add a unique constraint that would make reassignment an explicit ownership operation. The `accounts` table likewise stores the nation identifier on each account. The source therefore does not establish a database-level one-login-per-nation invariant.

The administrative update route is `PUT /admin/user/{user}` from `routes/admin/core.php`. The route is inside the authenticated, verified, Discord-verified, MFA-configured, and `AdminMiddleware`-protected group in `routes/web.php`. Mallory's starting position is therefore already privileged: she has a valid administrator session and the `edit-users` permission, but need not have `edit-roles`. She must choose a different target user that the role-delegation checks allow her to manage; the code rejects self-management without `bypass-self-restrictions` and rejects protected or higher-permission targets.

For the downstream step, the target login must be usable and retain the normal prerequisites enforced by the member routes: verification, an active Discord link, and configured MFA where required. Alice must have one or more account rows attached to her nation. Whether deployments commonly permit an operator to use a second login, and whether the target login has a particular MFA device available to Mallory, are not established by this source review.

## Vulnerability Details

The failure is the gap between the permission being checked and the security-sensitive field being changed. `UserController::update()` first authorizes `edit-users` and calls `RoleDelegationService::ensureCanManageUser()`:

```php
$this->authorize('edit-users');

/** @var User $actor */
$actor = $request->user();
$this->roleDelegationService->ensureCanManageUser($actor, $user);
```

This is `app/Http/Controllers/Admin/UserController.php:229-235` in v0.8.0-beta. The service limits which user records can be edited, but its checks in `app/Services/RoleDelegationService.php:110-129` concern self-management, protected roles, and the target's current permissions. They do not authorize a change to the target's nation binding or compare the old and new nation IDs.

The request validator treats `nation_id` as an ordinary profile field:

```php
'nation_id' => ['nullable', 'integer', 'exists:nations,id'],
```

After locking and rechecking the target user, the same method assigns the submitted value without any destination-owner or nation-transfer check:

```php
$user = User::query()->lockForUpdate()->findOrFail($user->id);
$this->roleDelegationService->ensureCanManageUser($actor, $user);

// ...

$user->nation_id = $validated['nation_id'] ?? null;
```

These lines are `app/Http/Controllers/Admin/UserController.php:237-260`. The row lock closes a concurrent-update window, but it does not change the authorization decision. If Mallory omits the `roles` field, the later `edit-roles` authorization branch is not reached, so a user editor without role-editing permission can still submit a nation change. The update is then saved as part of the transaction.

The migration history confirms that the behaviour was introduced with the original admin user-editing change `b14194dd` and that the assignment was present in the earliest verified affected tag, v0.3.0-alpha. The later delegation refactor `a84993b2` moved the update into a transaction and added the role-delegation checks, but retained the same `nation_id` validation and assignment without adding a nation-binding authorization decision. I checked the relevant update logic in the v0.3.0-alpha and v0.8.0-beta snapshots. The introducing commit is contained by the repository's v0.3.0-alpha, v0.4.0-alpha, v0.5.0-alpha, v0.7.0-alpha, and v0.8.0-beta tags; no fixing change or fixed release was found in the inspected history.

After the update, the target's account relationship follows Alice's nation:

```php
return $this->hasMany(Account::class, 'nation_id', 'nation_id');
```

`app/Models/User.php:77-80` makes the lookup key explicit. Member account transfers also compare the source account with the authenticated user's nation and use that same authenticated nation as the destination:

```php
$fromAccount = Account::findOrFail($request->input('from'));
if ($fromAccount->nation_id !== Auth::user()->nation_id) {
    throw ValidationException::withMessages([
        'from' => ['You do not own the source account.'],
    ]);
}

// ...

$transaction = AccountService::transferToNation(
    $request->input('from'),
    Auth::user()->nation_id,
    $transfer
);
```

This is `app/Http/Controllers/AccountsController.php:149-206`. The check is correct for an immutable, trusted user-to-nation binding; it becomes the wrong boundary after the administrator update lets that binding be reassigned arbitrarily.

The statement controller applies the same assumption when building the target user's account list and selecting an account:

```php
return Account::query()
    ->where('nation_id', (int) $user->nation_id)
    ->orderBy('name')
    ->orderBy('id')
    ->get();
```

```php
return Account::query()
    ->where('nation_id', (int) $user->nation_id)
    ->findOrFail($accountId);
```

These are `app/Http/Controllers/AccountStatementController.php:212-237`. The source establishes the complete state transition and the downstream lookup. It does not establish that every target login is available to Mallory, that Alice's accounts contain funds, or that a specific transfer would be accepted by all business rules.

## Exploitability Analysis

The realistic primitive is an authorization failure in account-to-nation binding, not an arbitrary unauthenticated account takeover. Mallory needs a live administrator session with `edit-users`, a distinct target user that passes the existing role-delegation ceiling, the numeric ID of Alice's existing nation, and a target login she can use after the update. The target must still pass the normal member-route requirements. Mallory cannot use this path to edit her own login unless she already has the separate `bypass-self-restrictions` permission.

A source-confirmed positive control is the ordinary member check: an unchanged login can select only accounts whose `nation_id` equals its authenticated `User::nation_id`, and a mismatched account is rejected by `AccountsController::transfer()` or `viewAccount()`. That policy is sound when the binding is correct. The vulnerable transition changes the value that every one of those checks trusts.

The meaningful negative controls are also source-confirmed. An invalid or nonexistent nation ID fails request validation. A protected target, a target with permissions above Mallory's, or Mallory's own user without `bypass-self-restrictions` is rejected by `ensureCanManageUser()`. Supplying a `roles` field additionally reaches the `edit-roles` check, but omitting roles leaves the nation assignment exposed to `edit-users` alone. These controls rule out a claim that the finding bypasses every administrative boundary; they do not provide a control for the missing nation-transfer policy.

The expected stronger effect is conditional on the target login and account state. If the target login is usable by Mallory and Alice owns accounts with balances or sensitive statement history, the source supports access to those nation-scoped records and the existing transfer workflow. This report does not claim that Mallory can steal Alice's session, bypass MFA or Discord verification, create Alice's accounts, force a transfer past its separate amount and approval rules, or obtain code execution. No runtime experiment was authorized or performed, so reliability and deployment prevalence remain unknown.

## Proof of Concept

No PoC file, run record, response body, or observed output exists. I reviewed the pinned source and release history only; I did not send requests to a live or local application and did not change database state.

The following is the expected request shape, included to make the source-confirmed path checkable. It is not an executed exploit. Mallory's session must satisfy the administrative middleware, and `<target-user-id>` must identify a different manageable user whose existing name, email, and status values are supplied as required by the validator:

```http
PUT /admin/user/<target-user-id>
Content-Type: application/x-www-form-urlencoded

name=<unchanged-target-name>&email=<unchanged-target-email>&is_admin=0&disabled=0&nation_id=<alice-nation-id>
```

The expected source-level result is a successful redirect after the transaction saves the target user's `nation_id` as Alice's nation. The target login would then be expected to see Alice's nation-scoped accounts at `GET /accounts`, select them through the account and statement routes, and pass the same-nation comparison in the transfer handler. Those are predictions from the inspected code, not observed HTTP responses. A safe reproduction should use disposable users and accounts in a private test database and should verify the target's preconditions without weakening or removing MFA, Discord verification, or other middleware.

## Remediation

The generic user-profile update should not treat `nation_id` as an ordinary editable field. The safest compatible change is to remove it from this endpoint and place reassignment behind a dedicated nation-binding or ownership-transfer workflow. That workflow should require a distinct permission that is not implied by `edit-users`, verify that the actor is allowed to manage both the source and destination nation according to the deployment's alliance/tenant policy, and recheck the decision after locking the target row inside the transaction.

If multiple logins per nation are intentional, the service should make that policy explicit and record the source nation, destination nation, actor, reason, and resulting binding in the audit event. If only one login may represent a nation, add and migrate the corresponding database invariant after cleaning existing duplicates. In either case, downstream finance authorization should continue to scope accounts by an independently trusted binding rather than assuming that a generic profile edit is an approved ownership transfer.

Regression coverage should exercise the real `PUT /admin/user/{user}` route with an actor that has only `edit-users`: changing `nation_id` must be rejected or ignored, and the target row must remain bound to its original nation. Add a successful case for the dedicated permission or approved transfer workflow, a negative case for an unmanaged destination, and a post-change member-finance check proving that a login cannot select or transfer from Alice's accounts merely because a generic profile request supplied Alice's nation ID. No fix has been inspected or verified, so these are proposed remediation and test requirements rather than claims about a shipped release.

## Summary

From v0.3.0-alpha, the admin user-update flow accepts an existing nation ID and writes it to a manageable user's `users.nation_id`; v0.8.0-beta retains the behaviour after the role-delegation refactor. A limited administrator with `edit-users` can therefore rebind a distinct usable login to Alice's nation without a separate nation-transfer authorization. The member account and statement handlers correctly trust that binding, so the narrow demonstrated impact is unauthorized access to, and potentially authorized finance operations against, Alice's nation-scoped records when the stated login, MFA, verification, Discord, and account prerequisites hold.

No fixed release is verified. The most useful next validation is a disposable end-to-end test that keeps all normal middleware enabled, records the pre- and post-update nation IDs, and confirms both the expected account lookup and the negative control for an unchanged or unauthorized binding.
