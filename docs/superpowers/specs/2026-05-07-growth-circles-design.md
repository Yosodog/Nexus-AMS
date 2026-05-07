# Growth Circles — Design Spec

**Date:** 2026-05-07
**Status:** Draft, pending review
**Branch:** `feature/growth-circles`

## 1. Summary

Growth Circles is an opt-in member program in which an enrolled nation pays 100% tax to the alliance and, in return, receives a daily ledger credit of food and uranium equal to the nation's computed daily consumption. Credits accumulate in a member-selected internal `Account` and are withdrawn through the existing AMS withdrawal flow.

The program runs in parallel with DirectDeposit. A nation can be enrolled in at most one of the two programs at a time; switching between them is automatic and preserves the nation's original (pre-program) tax bracket so it can always be restored.

## 2. Goals and non-goals

**Goals**
- Provide a "100% tax in exchange for guaranteed sustenance" program that members can opt into and out of without admin involvement.
- Reuse existing primitives (tax bracket mutation, internal accounts, profitability snapshots, finance event bus, audit log).
- Keep the program isolated from DirectDeposit such that a bug in one cannot cascade into the other.
- Make distribution idempotent against scheduler retries and admin manual triggers.

**Non-goals**
- Abuse detection / suspended-for-cause state. Computed game consumption (rather than stockpile delta) is structurally abuse-resistant; if needed later, add as a follow-up feature.
- Automated tests. Per project policy in `CLAUDE.md`, verification is manual.
- Refactoring DirectDeposit into a generic "tax program" abstraction. Two parallel services is acceptable; refactor only when a third program appears.
- In-game bank transfers as part of the daily distribution. Distribution credits the internal ledger only; withdrawals continue to use the existing flow.

## 3. Key design decisions

| Decision | Choice | Rationale |
|---|---|---|
| What does "need" mean? | Computed daily **shortfall**: `max(0, -resource_profit_per_day[$resource])` for food and uranium, from `NationProfitabilityService` snapshots | Not gameable by selling/depositing/gifting stockpile. P&W consumes from production *before* tax, so a net producer breaks even at 100% tax (alliance receives the surplus, member's stockpile stays steady) and needs nothing shipped. A net consumer's stockpile drops by the shortfall amount per day; the alliance ships exactly that to keep them whole. |
| Distribution cadence | Daily, scheduled at 03:00 UTC | Matches existing scheduler patterns; bounded burst size; low log noise. |
| Delivery destination | Internal `Account` selected by the member at enrollment | No in-game bank API calls in the daily job; reuses existing withdrawal flow with its blockade and rate-limit handling. |
| DD ↔ Growth Circles conflict | Auto-switch | Smoother UX. Auto-switch always preserves the *original* `previous_tax_id`, never the other program's bracket. |
| Bank-balance shortfall | Credit ledger debt regardless | Simplest. In-game shortfalls surface at withdrawal time through existing error path. |
| Eligibility gates | Block enrollment AND skip distribution for: not in alliance group; applicant; vacation mode; beige; zero cities | Same five gates apply to both enrollment and per-cycle distribution. Members who later violate a gate are paused (no distribution) and auto-resume when the condition clears, no re-enrollment needed. |

## 4. Architecture

### 4.1 Components

```
┌──────────────────────────────────────────────────────────────┐
│ User enrollment                                              │
│  POST /growth-circles/enroll → GrowthCirclesController       │
│   → GrowthCircleService::enroll($nation, $account)           │
│      • run 5 eligibility gates                               │
│      • if DD enrolled: capture original previous_tax_id,     │
│        call DirectDepositService::disenroll                  │
│      • upsert GrowthCircleEnrollment row                     │
│      • TaxBracketService → P&W: assign growth_circles.tax_id │
│      • AuditLogger::recordAfterCommit                        │
└──────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────┐
│ Daily distribution                                           │
│  Schedule::command('growth-circles:distribute')              │
│   ->dailyAt('03:00')->withoutOverlapping(120)                │
│   ->skip(if PWHealthService not up)                          │
│   → DistributeGrowthCirclesCommand                           │
│   → GrowthCircleService::runDailyDistribution()              │
│      foreach enrollment (chunked):                           │
│        • re-check 5 eligibility gates                        │
│        • NationProfitabilityService::getDailyResourceShortfall     │
│        • DB::transaction:                                    │
│           – lock account, credit food + uranium              │
│           – insert GrowthCircleDistribution row              │
│           – emit AllianceExpenseOccurred                     │
└──────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────┐
│ Tax record processing                                        │
│  Existing pipeline; 100% bracket retains everything.         │
│  No new code. DirectDepositService::process short-circuits   │
│  on non-DD tax_id (already does).                            │
└──────────────────────────────────────────────────────────────┘
```

### 4.2 Isolation properties

- The Growth Circle service does not import `DirectDepositService` for any purpose other than calling its public `disenroll()` method during auto-switch. No shared state, no shared tables.
- A symmetric ~5-line addition to `DirectDepositService::enroll()` checks for an existing Growth Circles enrollment and disenrolls it before proceeding. This is the only DD code change in this feature.
- Consumption math is read-only against `NationProfitabilityService`. We add a thin `getDailyResourceShortfall(Nation): array{food, uranium}` accessor; we do not duplicate or fork the existing computation.
- All P&W tax bracket mutations go through the existing `TaxBracketService`.

## 5. Data model

### 5.1 `growth_circle_enrollments`

| column | type | notes |
|---|---|---|
| `id` | bigint PK | |
| `nation_id` | unsigned bigint, **unique**, FK → `nations.id` | one row per nation max |
| `account_id` | unsigned bigint, FK → `accounts.id` | destination |
| `previous_tax_id` | int, nullable | original pre-program bracket; restored on disenroll |
| `enrolled_at` | timestamp | |
| `created_at` / `updated_at` | timestamps | |

Concurrency: unique constraint on `nation_id`. The service uses `updateOrCreate` keyed on `nation_id`, which is naturally idempotent across double-submits — the constraint is a defense-in-depth backstop, not the primary guard.

No `pending_key` pattern: enrollment is instantaneous, not request/approval.

### 5.2 `growth_circle_distributions`

| column | type | notes |
|---|---|---|
| `id` | bigint PK | |
| `nation_id` | unsigned bigint, FK → `nations.id` | |
| `account_id` | unsigned bigint, FK → `accounts.id` | snapshot — preserved if account later deleted |
| `enrollment_id` | unsigned bigint, **nullable** FK → `growth_circle_enrollments.id` | nulled on enrollment deletion |
| `food` | decimal(20,2) | shipped this cycle (= the shortfall at cycle time) |
| `uranium` | decimal(20,2) | shipped this cycle (= the shortfall at cycle time) |
| `cycle_date` | date | |
| `created_at` | timestamp | |

Constraints:
- **Unique `(nation_id, cycle_date)`** — makes daily distribution idempotent across scheduler retries, server restarts mid-run, and admin manual re-runs.
- Index on `cycle_date` for admin history queries.

### 5.3 Settings (new keys via `SettingService`)

- `growth_circles.tax_id` — the P&W bracket ID with 100% retention across all resources. Admin must create the bracket in P&W and enter the ID here.
- `growth_circles.fallback_tax_id` — bracket assigned on disenroll if `previous_tax_id` is null or invalid (mirrors DD's fallback pattern).

Add the following accessors on `SettingService` (mirroring the DD pattern of `getDirectDepositId()` / `getDirectDepositFallbackId()`):

```php
public static function getGrowthCirclesTaxId(): ?int
public static function getGrowthCirclesFallbackTaxId(): ?int
```

The service, the admin Settings view, and the command all read through these accessors — no ad-hoc `config()` or `Setting::get()` calls scattered across the feature.

### 5.4 No new columns on `nation_profitability_snapshots`

The shortfall metric is read directly from the existing `resource_profit_per_day` JSON column on `nation_profitability_snapshots` (already populated for every member nation by the existing snapshot pipeline — see `app/Services/NationProfitabilityService.php` line 316). No schema change to that table is required.

For each enrolled nation per cycle:

```php
$net = $snapshot->resource_profit_per_day;          // array<string, float>
$foodShortfall    = max(0, -(float) ($net['food']    ?? 0));
$uraniumShortfall = max(0, -(float) ($net['uranium'] ?? 0));
```

Net producers (`food` >= 0) get `0`. Net consumers get the absolute value of their daily deficit.

### 5.5 Permissions (new entries in `config/permissions.php`)

- `view-growth-circles`
- `manage-growth-circles`

Diagnostic actions (manual distribution trigger, reapply bracket) additionally require the existing `view-diagnostic-info` permission per `CLAUDE.md` guidance.

## 6. Enrollment flow

### 6.1 Enroll

```
GrowthCircleService::enroll(Nation $nation, Account $account)

  // Step 1: eligibility gates run FIRST, before any state capture or
  // side effect. If any gate fails the operation aborts immediately.
  guard: 5 eligibility gates
    – AllianceMembershipService::contains($nation->alliance_id)
    – $nation->alliance_position !== APPLICANT
    – $nation->vacation_mode_turns === 0
    – $nation->color !== 'beige'
    – $nation->num_cities > 0
  on failure → throw UserErrorException with the failing gate

  // Step 2: capture previous_tax_id BEFORE any disenroll side effect.
  // Variable order is load-and-snapshot-first, mutate-second.
  if ($ddEnrollment = DirectDepositEnrollment::where('nation_id', $nation->id)->first()) {
      $previousTaxId = $ddEnrollment->previous_tax_id;          // capture FIRST
      app(DirectDepositService::class)->disenroll($nation);     // then mutate

      // If DD disenroll fell through to fallback (logged warning) the bracket
      // is currently the fallback, but we still proceed with the captured
      // previousTaxId — the final disenroll-from-GC at some future point will
      // restore the original. Aborting here would leave the user with the
      // fallback bracket and no enrollment.
  } elseif ($existing = GrowthCircleEnrollment::where('nation_id', $nation->id)->first()) {
      $previousTaxId = $existing->previous_tax_id;              // idempotent re-enroll
  } else {
      $previousTaxId = $nation->tax_id;
  }

  // Step 3: persist enrollment.
  DB::transaction:
    GrowthCircleEnrollment::updateOrCreate(
      ['nation_id' => $nation->id],
      [
        'account_id'      => $account->id,
        'previous_tax_id' => $previousTaxId,
        'enrolled_at'     => now(),
      ]
    )

  // Step 4: assign new bracket in P&W. Failure leaves the row in place; the
  // admin "Reapply tax bracket" recovery action retries.
  TaxBracketService → P&W: assign growth_circles.tax_id

  AuditLogger::recordAfterCommit('growth_circles.enrolled', actor, payload)
```

**Switch failure semantics.** During an auto-switch from DD → GC, if `DirectDepositService::disenroll()` falls back (its primary `TaxBracketService` call fails and it retries with the DD fallback bracket), the GC enroll proceeds anyway. The user ends up enrolled in GC with the captured original `previous_tax_id` — a final disenroll from GC will restore the original bracket. Aborting the enroll on a partial DD-disenroll failure would leave the user disenrolled from DD but not enrolled in GC, which is worse. The same logic applies symmetrically to GC → DD switches in `DirectDepositService::enroll()`.

If the GC `TaxBracketService` call itself fails after the row is written (Step 4), the error is surfaced to the user; the enrollment row exists but the in-game bracket isn't set. The admin "Reapply tax bracket" recovery action handles this case.

### 6.2 Disenroll

```
GrowthCircleService::disenroll(Nation $nation)

  load enrollment; bail silently if missing (idempotent)

  TaxBracketService → P&W: assign previous_tax_id
    on failure → retry with growth_circles.fallback_tax_id
    on second failure → log error, but still delete the enrollment row
                        (mirrors DD; dangling row would freeze the user)

  delete enrollment row

  AuditLogger::recordAfterCommit('growth_circles.disenrolled', actor, payload)
```

### 6.3 Symmetric DD change

`DirectDepositService::enroll()` gains, at the top (capture-then-mutate ordering — same pattern as §6.1):

```php
if ($gcEnrollment = GrowthCircleEnrollment::where('nation_id', $nation->id)->first()) {
    $previousTaxId = $gcEnrollment->previous_tax_id;          // capture FIRST
    app(GrowthCircleService::class)->disenroll($nation);      // then mutate
    // Same fallback semantics: proceed with DD enroll even if GC disenroll
    // fell through to GC's fallback bracket.
} else {
    $previousTaxId = ($currentTaxId === $ddTaxId)
        ? SettingService::getDirectDepositFallbackId()
        : $currentTaxId;
}
```

The auto-switch always carries forward the *original* `previous_tax_id`, never the other program's tax_id. Repeated switches always restore the true original bracket on final disenroll.

## 7. Daily distribution

### 7.1 Command

```
php artisan growth-circles:distribute
```

`DistributeGrowthCirclesCommand` is a thin wrapper that calls `GrowthCircleService::runDailyDistribution()` and prints success/skip/failure counts.

### 7.2 Schedule

```php
// routes/console.php
Schedule::command('growth-circles:distribute')
    ->dailyAt('03:00')
    ->withoutOverlapping(120)
    ->skip(fn () => ! app(PWHealthService::class)->isUp());
```

03:00 UTC chosen for off-peak load and to land after the typical tax cycle has settled. `withoutOverlapping(120)` — the 120-minute lock guards against a slow run blocking the next cycle.

### 7.3 `runDailyDistribution()`

```php
$cycleDate = Carbon::now('UTC')->toDateString();

GrowthCircleEnrollment::query()
    ->with(['nation', 'account'])
    ->whereHas('nation')
    ->chunkById(200, function ($enrollments) use ($cycleDate) {
        foreach ($enrollments as $enrollment) {
            $this->distributeOne($enrollment, $cycleDate);
        }
    });
```

### 7.4 `distributeOne()`

Each member is wrapped in its own try/catch + DB transaction so one bad nation cannot kill the cycle.

**Transaction-and-catch ordering matters.** The `try/catch` is **outside** the `DB::transaction()`, never inside it. On a unique-violation (`1062`) the transaction rolls back first, then the exception bubbles to the outer catch which logs-and-skips. Moving the catch *inside* the transaction would attempt a write after the violation and break idempotency.

```php
try {
    DB::transaction(function () use ($enrollment, $cycleDate) {
        $nation = $enrollment->nation;

        // 1. Re-check 5 eligibility gates against fresh nation state.
        if (! $this->isEligible($nation)) {
            Log::info('growth_circles.skip', [...]);
            return;
        }

        // 2. Pull daily shortfall (consumption above production) from the
        //    nation's latest profitability snapshot.
        $shortfall = app(NationProfitabilityService::class)
            ->getDailyResourceShortfall($nation);
        if ($shortfall === null) {
            Log::warning('growth_circles.no_snapshot', ['nation_id' => $nation->id]);
            return;
        }

        // 3. Lock the destination account, credit, log.
        $account = Account::whereKey($enrollment->account_id)
            ->lockForUpdate()->first();
        if (! $account) {
            Log::error('growth_circles.account_missing', [...]);
            return;
        }

        // Direct column increment matches the existing pattern used by
        // DirectDepositService::process() (see Account columns: each P&W
        // resource — money, food, uranium, etc. — is a plain decimal column
        // on `accounts`, mutated in-place under a row lock).
        $account->food    += $shortfall['food'];
        $account->uranium += $shortfall['uranium'];
        $account->save();

        GrowthCircleDistribution::create([
            'nation_id'     => $nation->id,
            'account_id'    => $account->id,
            'enrollment_id' => $enrollment->id,
            'food'          => $shortfall['food'],
            'uranium'       => $shortfall['uranium'],
            'cycle_date'    => $cycleDate,
        ]);

        // 4. Emit alliance expense for finance reporting.
        event(new AllianceExpenseOccurred(
            AllianceFinanceData::forGrowthCircleDistribution($nation, $account, $shortfall)
                ->toArray()
        ));
    });
} catch (UniqueConstraintViolationException $e) {
    // Already distributed for this cycle_date — idempotent skip.
    // Transaction has already rolled back at this point.
    // Use Laravel's portable wrapper rather than catching driver-specific
    // SQLSTATE / errno values.
    return;
} catch (Throwable $e) {
    Log::error('growth_circles.distribute.failed', [
        'nation_id' => $enrollment->nation_id,
        'message'   => $e->getMessage(),
    ]);
    // continue to next member
}
```

### 7.5 New method on `NationProfitabilityService`

```php
/**
 * Daily food/uranium shortfall (consumption above production) for the
 * given nation, read from its latest profitability snapshot.
 *
 * @return array{food: float, uranium: float}|null
 *         Null if no snapshot exists for this nation.
 *         For each resource: max(0, -resource_profit_per_day[$resource]).
 *         Net producers receive 0; net consumers receive their deficit.
 */
public function getDailyResourceShortfall(Nation $nation): ?array
```

Implementation reads `NationProfitabilitySnapshot::query()->where('nation_id', $nation->id)->latest('calculated_at')->first()` — the explicit ordering matches the docblock's "latest snapshot" guarantee (the table currently only stores one row per nation via `updateOrCreate`, but the ordering protects against a future change to that). If no snapshot exists, returns `null` and the daily distribution skips the member with a `growth_circles.no_snapshot` warning. The accessor does not trigger snapshot regeneration — snapshots are produced by the existing scheduled job and we accept whatever the most recent one says.

## 8. Routes

### 8.1 User routes

```php
// routes/web.php (within auth middleware group)
Route::post('/growth-circles/enroll',
    [GrowthCirclesController::class, 'enroll']
)->name('growth-circles.enroll');

Route::post('/growth-circles/disenroll',
    [GrowthCirclesController::class, 'disenroll']
)->name('growth-circles.disenroll');
```

### 8.2 Admin routes

```php
// routes/web.php (within admin middleware group)
Route::get('/admin/growth-circles',
    [Admin\GrowthCirclesController::class, 'index']
)->middleware('can:view-growth-circles');

Route::get('/admin/growth-circles/history',
    [Admin\GrowthCirclesController::class, 'history']
)->middleware('can:view-growth-circles');

Route::post('/admin/growth-circles/settings',
    [Admin\GrowthCirclesController::class, 'saveSettings']
)->middleware('can:manage-growth-circles');

Route::post('/admin/growth-circles/enrollments/{nation}/disenroll',
    [Admin\GrowthCirclesController::class, 'forceDisenroll']
)->middleware('can:manage-growth-circles');

Route::post('/admin/growth-circles/enrollments/{nation}/reapply-bracket',
    [Admin\GrowthCirclesController::class, 'reapplyBracket']
)->middleware('can:manage-growth-circles,view-diagnostic-info');
```

## 9. User-facing UI (Tailwind + DaisyUI)

Dashboard card alongside the existing DirectDeposit card.

### 9.1 Not enrolled in either program

- Title: "Growth Circles"
- Explainer: "Contribute 100% of your tax income. In return, the alliance ships your daily food and uranium consumption to your selected account."
- Account picker (member's existing accounts; default = primary).
- Eligibility line — green if all five gates pass, red with the specific failing gate if not (e.g. "Not available while in vacation mode").
- "Enroll" button — disabled when ineligible.

### 9.2 Enrolled in Growth Circles (active)

- Status: "Enrolled — depositing into *Account Name*."
- Last distribution: date, food shipped, uranium shipped.
- Next distribution: "tomorrow ~03:00 UTC."
- Recent history: collapsible list, last 7 cycles.
- "Disenroll" button.

### 9.2.1 Enrolled in Growth Circles (paused — eligibility gate failing)

When a nation is enrolled but the daily distribution is skipping it because one of the 5 gates currently fails:

- Status banner (warning color): "Paused — *reason*" (e.g. "Paused — vacation mode," "Paused — beige," "Paused — applicant rank").
- Explainer: "You will resume receiving distributions automatically when this condition clears."
- Recent history still shown; the paused-cycle days simply have no distribution row.
- "Disenroll" button still available — enrollment is not auto-cancelled by a pause.

### 9.3 Enrolled in DirectDeposit

- Title and explainer as in §9.1.
- "Switch from DirectDeposit" button (executes the auto-disenroll-then-enroll).
- Confirmation dialog: "This will disenroll you from DirectDeposit and enroll you in Growth Circles. Your tax bracket will change to the 100% Growth Circles bracket. Continue?"

### 9.4 Form Request validation

- `account_id`: required, exists in `accounts`, belongs to authenticated user's `nation_id`.
- Eligibility re-checked in service layer. The view check is for UX; the service guard is for correctness.

## 10. Admin UI (Bootstrap + AdminLTE)

Three pages, all gated by `view-growth-circles`; destructive actions additionally require `manage-growth-circles`.

### 10.1 Settings (`/admin/growth-circles/settings`)

Slot into the existing admin Settings page pattern (same shape as DirectDeposit and Rebuilding).

- Input: `growth_circles.tax_id`.
- Input: `growth_circles.fallback_tax_id`.
- Inline help: "Both brackets must already exist in the P&W tax bracket list."
- Save button gated on `manage-growth-circles`.

### 10.2 Enrollments index (`/admin/growth-circles`)

Table of all enrolled nations. Columns:
- Nation, cities, account name, enrolled date, last distribution (date + food/uranium), 7-day total received, eligibility status (with reason if blocked).

Filters: search by nation name, eligibility = blocked-only.

Per-row actions (gated `manage-growth-circles`):
- **Force-disenroll** — runs `GrowthCircleService::disenroll`.
- **Reapply tax bracket** — recovery tool: re-runs `TaxBracketService` from the existing enrollment row. Behind `view-diagnostic-info`.

Manual distribution is performed via the existing artisan command (`php artisan growth-circles:distribute`) using whatever operations tooling the project already uses for one-off scheduled-job runs — no admin-UI button is added. The unique `(nation_id, cycle_date)` constraint keeps repeated same-day invocations idempotent.

### 10.3 Distribution history (`/admin/growth-circles/history`)

Audit trail across all members. Columns:
- `cycle_date`, nation, account, food shipped, uranium shipped.

Filters: date range, nation, account.
Pagination: 50/page.
CSV export of current filter (matches existing tax/finance export patterns).

## 11. Audit logging

All write actions log via `AuditLogger`, using `recordAfterCommit()` for transaction-wrapped changes.

| Action | Event key |
|---|---|
| User enrolls (no prior DD) | `growth_circles.enrolled` |
| User enrolls via auto-switch from DD | `growth_circles.switched_from_dd` (replaces `growth_circles.enrolled`; not in addition to) |
| User disenrolls | `growth_circles.disenrolled` |
| User enrolls in DD via auto-switch from GC | `direct_deposit.switched_from_growth_circles` (replaces `direct_deposit.enrolled`; not in addition to) |
| Admin force-disenroll | `growth_circles.admin_disenrolled` |
| Admin reapply bracket | `growth_circles.bracket_reapplied` |
| Settings updated | `growth_circles.settings_updated` |

The switch events **replace** the underlying enroll events rather than being emitted alongside them — single audit row per user-visible action, with payload distinguishing the auto-switch case.

The daily distribution is **not** audit-logged per row (would be noisy). The `growth_circle_distributions` table itself is the audit trail.

## 12. Notifications

- On enroll / disenroll: success flash + audit entry. No Discord ping per member event.
- On distribution: no per-member notification. Members see results on the dashboard.
- On admin actions: standard audit entries.

The "no per-distribution notification" choice keeps Discord channel noise low; with 50+ enrolled members, a daily ping per member would drown the alliance audit channel.

## 13. Error handling

| Failure | Where | Behavior |
|---|---|---|
| `TaxBracketService` fails on enroll | service | DB row exists; throw → user sees error. Admin "Reapply tax bracket" can retry. |
| `TaxBracketService` fails on disenroll | service | retry with `fallback_tax_id`; on second failure, log and still delete the enrollment row (mirrors DD; dangling row would freeze the user). |
| `NationProfitabilityService` returns null | distribute | log warning, skip member for the cycle. |
| Unique violation `(nation_id, cycle_date)` | distribute | catch `UniqueConstraintViolationException`, treat as already-done, skip silently. |
| Account locked / missing | distribute | log error, skip member, continue cycle. |
| One member's distribution throws | distribute | per-member try/catch isolates it; cycle continues. |
| `growth_circles.tax_id` not configured | enroll | block with admin-facing error: "Growth Circles tax bracket is not configured." |
| P&W API down at scheduled time | scheduler | `->skip()` skips the cycle entirely; resumes next day. |
| Concurrent enrollment (double-click, same `account_id`) | service | `updateOrCreate` on `nation_id` is naturally idempotent — last write wins, no constraint violation. |
| Concurrent enrollment (double-submit, *different* `account_id`) | service | `updateOrCreate` last-write-wins on `account_id`; both calls succeed serially and the second-clicked account becomes active. Acceptable: this is recoverable by the user re-submitting with their preferred account. |

## 14. Cache invalidation

None required. This feature reads from `NationProfitabilityService` (which manages its own cache) and writes to dedicated tables. No existing cache key includes Growth Circles state.

## 15. Verification (manual QA)

Per `CLAUDE.md` "no automated tests" policy:

1. **Settings setup** — set up test brackets in P&W staging; configure `tax_id` and `fallback_tax_id`.
2. **Basic enroll** — enroll a test nation. Confirm enrollment row written and bracket changed in P&W.
3. **Manual distribute** — `php artisan growth-circles:distribute`. Confirm distribution row written and account credited correctly.
4. **Cross-program switching** — enroll DD → switch to GC → switch back to DD → disenroll. After every step, verify P&W bracket and DB rows match expectations and that the original pre-DD bracket is preserved on final disenroll.
5. **Eligibility pause** — put a nation in vacation mode. Confirm distribution skips and dashboard shows "paused — vacation mode."
6. **Idempotency** — run `growth-circles:distribute` twice in the same UTC day. Confirm the second run produces no new credit and no new distribution rows.
7. **API failure recovery** — block network during enroll. Confirm "Reapply tax bracket" recovery action restores correct bracket assignment.
8. **Concurrent enrollment** — open two browser tabs, click enroll simultaneously. Confirm only one row is created and the second click produces a friendly error.
9. **Force-disenroll** — admin force-disenroll an enrolled member. Confirm bracket restored to `previous_tax_id` and row deleted.
10. **Distribution history export** — generate CSV; confirm columns and filtering match expectations.

## 16. Files to create / modify

### New files

- `database/migrations/<ts>_create_growth_circle_enrollments_table.php`
- `database/migrations/<ts>_create_growth_circle_distributions_table.php`
- `app/Models/GrowthCircleEnrollment.php`
- `app/Models/GrowthCircleDistribution.php`
- `app/Services/GrowthCircleService.php`
- `app/Console/Commands/DistributeGrowthCirclesCommand.php`
- `app/Http/Controllers/GrowthCirclesController.php`
- `app/Http/Controllers/Admin/GrowthCirclesController.php`
- `app/Http/Requests/EnrollGrowthCirclesRequest.php`
- `resources/views/growth-circles/_card.blade.php` (user dashboard partial)
- `resources/views/admin/growth-circles/index.blade.php`
- `resources/views/admin/growth-circles/history.blade.php`
- `resources/views/admin/growth-circles/settings.blade.php` (or partial for the settings page)

### Modified files

- `routes/web.php` — user + admin routes.
- `routes/console.php` — daily schedule entry.
- `config/permissions.php` — `view-growth-circles`, `manage-growth-circles`.
- `app/Services/DirectDepositService.php` — symmetric ~5-line auto-switch in `enroll()`.
- `app/Services/NationProfitabilityService.php` — add `getDailyResourceShortfall(Nation): ?array`.
- `app/Services/SettingService.php` — add `getGrowthCirclesTaxId(): ?int` and `getGrowthCirclesFallbackTaxId(): ?int` (mirroring `getDirectDepositId()` / `getDirectDepositFallbackId()`).
- `resources/views/dashboard.blade.php` (or wherever the DD card lives) — include the Growth Circles card.
- `resources/views/admin/settings/*` — settings section partial.
- `app/DataTransferObjects/AllianceFinanceData.php` — `forGrowthCircleDistribution()` factory method (or equivalent existing pattern).

## 17. Open questions / future work

- **Abuse vector — "skip the farms, get free food."** Because shortfall scales inversely with farm coverage, a member who builds zero farms maximizes their daily food shipment (population/military still eat; nothing offsets it). Same logic for uranium with no uranium-mine coverage and high military. This is a known consequence of the shortfall model — the alliance is effectively subsidizing under-built infrastructure. Mitigations to consider as a follow-up: minimum farms-per-city threshold for eligibility, or a per-cycle cap proportional to expected shortfall at recommended farm coverage.
- **Multi-resource expansion.** The schema is food/uranium-specific by intent. If the program later expands to more resources, the migration adds columns to `growth_circle_distributions` and the service reads more keys from `resource_profit_per_day`.
- **Distribution history retention.** No purge policy in this design; rows persist indefinitely. If table size becomes a concern, add a periodic archive command.
