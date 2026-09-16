## Executive Summary

In v0.8.0-beta, Mallory can use an authenticated limited administrator account that has the edit-users permission to promote Alice, an otherwise ordinary manageable user, by submitting Alice's user update with is_admin=true and omitting roles. The update accepts that boolean and persists it even though Mallory does not have edit-roles or a separate authority to grant administrator access. Alice then passes the application's administrator middleware, and an API branch that relies on is_admin treats her as authorized to view any war. This is a privilege escalation from limited user administration to the global administrator identity; it is not session theft, a TLS failure, or a protocol-authentication bypass.

The assessed release is v0.8.0-beta. The earliest affected release I could verify is v0.3.0-alpha: the v0.1.0-alpha and v0.2.0-alpha snapshots do not contain the admin user-update endpoint, while v0.3.0-alpha, v0.4.0-alpha, v0.5.0-alpha, v0.7.0-alpha and v0.8.0-beta all contain the vulnerable assignment. The user-editing operation was introduced by b14194d; the later delegation refactor a84993b added target and role checks but did not protect the is_admin transition. No fixed tag or inspected fix is available, so no fixed release can be stated. Untagged intermediate builds were not individually classified.

Severity: Medium. I reviewed the exact v0.8.0-beta source, the relevant introducing and refactoring history, the affected release tags and the related authorization tests. I did not send an exploit request or execute a privilege-changing test.

## Background

The user-management update is the PUT route named admin.users.update, defined at routes/admin/core.php:24-31 and loaded under the admin route group in routes/web.php:21-34. That group requires an authenticated session, a verified user, the configured Discord-verification policy, the configured MFA policy and AdminMiddleware. Disabled users are logged out by the web middleware. Discord verification and MFA are conditional deployment settings: if Discord verification is enabled, Mallory needs an active linked Discord account; if MFA is required for administrators, she needs enabled two-factor authentication. These settings are prerequisites for reaching the endpoint, not the source of the failure.

AdminMiddleware accepts a request when the session is authenticated and the current User record has is_admin set. UserController::update then separately authorizes edit-users. In the assessed source, the permission gate is backed by the permissions held through the user's roles (app/Providers/AppServiceProvider.php:297-303 and app/Models/User.php:134-143). Mallory therefore starts as an administrator for the middleware but has only the limited edit-users role permission; she does not have edit-roles or the proposed administrator-management authority.

Alice must be a target that the existing delegation rules allow Mallory to manage: she is not Mallory herself, she has no protected role unless Mallory has the bypass-self-restrictions permission, and the permissions derived from Alice's roles do not exceed Mallory's permissions. An enabled, verified ordinary user with no protected role is sufficient. Those checks make this a distinct transition from editing a manageable user's profile: they do not decide whether Mallory may change the target's global is_admin flag.

## Vulnerability Details

The expected policy is that edit-users permits profile maintenance but does not confer the ability to create another administrator. The refactoring added RoleDelegationService::ensureCanManageUser, but its checks concern the actor/target relationship only. In v0.8.0-beta, app/Services/RoleDelegationService.php:110-129 rejects self-edits without bypass-self-restrictions, protected targets without that permission, and targets whose role-derived permissions exceed the actor's. It never compares the target's current is_admin value with the requested value and never checks an administrator-promotion permission.

The route handler first enforces edit-users and calls that target check (app/Http/Controllers/Admin/UserController.php:229-235). Its validation requires is_admin to be a boolean but does not restrict the value to an authorized transition (app/Http/Controllers/Admin/UserController.php:237-247). Inside the locked transaction, the decisive assignment is:

    $user->is_admin = (bool) $validated['is_admin'];

This is app/Http/Controllers/Admin/UserController.php:256-258 in v0.8.0-beta. The method then invokes edit-roles only when the optional roles key is present:

    if (array_key_exists('roles', $validated)) {
        $this->authorize('edit-roles');

        $requestedRoleIds = collect($validated['roles'] ?? [])
            ->map(fn ($roleId): int => (int) $roleId)
            ->unique()
            ->values();
    }

These are app/Http/Controllers/Admin/UserController.php:271-277. Mallory can therefore provide the required profile fields and is_admin=true while omitting roles. The role-specific authorization and permission-ceiling checks are skipped, the transaction saves Alice with is_admin=true at line 298, and the later audit record does not undo the change. The row lock and second ensureCanManageUser call recheck target state inside the transaction; they do not add the missing state-transition authorization.

The administrative boundary trusts the persisted flag. AdminMiddleware contains the following check (app/Http/Middleware/AdminMiddleware.php:17-23):

    if (! Auth::check() || ! Auth::user()->is_admin) {
        abort(403, 'You must be an administrator to view this page.');
    }

    return $next($request);

After Alice's record is changed, this check succeeds for her. A concrete downstream example is the war API. Its route remains behind normal API authentication, verification, Discord and MFA middleware (routes/api/member.php:21-38), but WarSimulatorController::authorizeWarAccess returns immediately for an administrator before checking whether the requested war belongs to Alice's nation (app/Http/Controllers/API/WarSimulatorController.php:53-66):

    if ($user?->is_admin) {
        return;
    }

    if ($nationId && ((int) $war->att_id === (int) $nationId || (int) $war->def_id === (int) $nationId)) {
        return;
    }

    abort(403, 'You are not authorized to view this war.');

This demonstrates why the bit is a security boundary rather than a cosmetic profile field. Other administrative operations may still impose their own role permissions, so the source does not establish unrestricted access to every domain.

The original user-editing implementation introduced by b14194d already assigned the validated is_admin field directly; that behavior is present in the v0.3.0-alpha release and the later inspected release tags. Commit a84993b introduced RoleDelegationService and the transaction-backed target checks, but retained the same direct assignment and conditional roles branch. I found no later commit or release tag that protects the is_admin transition.

The related authorization test suite covers self-protection, protected targets, superior role assignments and a profile update by a user editor without edit-roles (tests/Feature/AdminRoleDelegationAuthorizationTest.php:19-138). The profile test confirms that roles can remain unchanged when roles is omitted, but it does not submit is_admin=true or assert that an ordinary target remains non-administrator. There is therefore no regression test for the failing transition.

## Exploitability Analysis

The supported primitive is persistent privilege escalation: Mallory crosses from the edit-users permission to Alice's global administrator identity by changing one user record. The attack needs a legitimate existing administrator session and the normal route prerequisites; it does not give an unauthenticated user a starting point. The target restrictions remain meaningful, so Mallory cannot use this exact path against herself, a protected target or a target above her role-derived permission ceiling unless she already holds the corresponding bypass permission.

The source supports the expected sequence for a single request. Mallory's target-management check succeeds for a manageable ordinary Alice, validation accepts true, the transaction writes the flag, and omitting roles avoids the edit-roles branch. A subsequent Alice session still needs the application's ordinary authentication, verification, Discord and MFA requirements. Once authenticated, the source confirms at least the AdminMiddleware boundary and the is_admin shortcut in the war-access check. Because different admin pages can apply separate gates, the evidence does not justify claiming that Mallory obtains every staff capability or arbitrary code execution.

There are useful controls, but they isolate rather than prevent this case. A normal edit-users profile update with roles omitted is intended to succeed, and the existing test at tests/Feature/AdminRoleDelegationAuthorizationTest.php:121-138 exercises that path. Requests targeting Mallory herself or a protected target are rejected by the existing service checks, as shown by tests/Feature/AdminRoleDelegationAuthorizationTest.php:19-64. Supplying roles would invoke edit-roles and the role permission ceiling, which is why omitting roles matters. None of these controls rejects an is_admin=true transition on a manageable ordinary target, and no runtime negative control was executed for this report.

The deployment prevalence of the relevant route and optional Discord/MFA settings is unknown. The issue does not depend on an unusual race, allocator behavior or external service response; it is a deterministic source-level authorization omission once Mallory already satisfies the normal route prerequisites.

## Proof of Concept

No runnable PoC artifact, request/response capture or execution trace exists. The following is the expected reproduction derived from the pinned source, not a claim that it was run:

1. In a disposable local installation of v0.8.0-beta, create Mallory as an enabled, verified administrator with the edit-users permission and without edit-roles. Satisfy the active Discord and MFA requirements if that installation enables them.
2. Create Alice as an enabled, verified ordinary user with no protected role and no role-derived permission outside Mallory's permission ceiling.
3. Using Mallory's authenticated session, submit the admin.users.update form for Alice with the required profile fields, is_admin=true and no roles field.
4. The expected result is the normal successful redirect and a database row for Alice with is_admin=true. That result was not observed here.
5. After Alice completes the normal login and any configured verification or MFA steps, request a war record that is not associated with her nation. The expected source-level result is that authorizeWarAccess returns through its is_admin branch instead of the nation-ownership check. No response was captured.

This procedure changes a user's privilege and must not be run against production. A disposable reproduction should restore Alice's original is_admin value and any other submitted profile fields afterward. No build step is needed for the source-only report, and there is no PoC directory or generated output to validate.

## Remediation

Protect the is_admin state transition with a dedicated permission that is separate from edit-users and edit-roles. For example, add a manage-administrators permission to the configured permission set, grant it only to the trusted administrative role, and centralize the transition check in RoleDelegationService. After the transaction locks and reloads the target, compare the current and requested values and authorize the transition before assigning it:

    $requestedIsAdmin = (bool) $validated['is_admin'];

    if ((bool) $user->is_admin !== $requestedIsAdmin) {
        $this->authorize('manage-administrators');
    }

    $user->is_admin = $requestedIsAdmin;

The check should run for both promotion and demotion, after the locked target is reloaded, while retaining the existing self, protected-target and role-ceiling checks. Do not infer administrator authority from the target's ordinary role permissions, and do not rely on the form hiding the field. Record the transition and actor in the existing audit entry after a successful commit.

Add regression coverage at the real admin.users.update entry point for a user editor with edit-users but without manage-administrators: an is_admin=true request with roles omitted must be forbidden and Alice must remain non-administrator. Add the positive case for a trusted actor, a demotion case, and checks that the existing protected-target and role-assignment protections still hold. No upstream fix was inspected, and no fixed release is known.

## Summary

In the verified v0.8.0-beta source, Mallory needs a legitimate enabled, verified administrator session with edit-users and the normal configured Discord/MFA prerequisites. Against a manageable ordinary Alice, she can submit the user-update form with is_admin=true while omitting roles; the handler performs the assignment without an administrator-transition check. Alice then satisfies AdminMiddleware and reaches is_admin-gated downstream behavior such as unrestricted war lookup, subject to normal API authentication and any configured verification/MFA requirements.

The earliest affected release I verified is v0.3.0-alpha, and the inspected v0.3.0-alpha, v0.4.0-alpha, v0.5.0-alpha, v0.7.0-alpha and v0.8.0-beta tags contain the behavior. No fixed tag or inspected fix exists. The report is source-validated only; a disposable feature test covering the omitted-roles promotion attempt is the most useful next runtime validation.
