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
| What does "need" mean? | Computed game consumption (food + uranium per day) from `NationProfitabilityService` | Not gameable by selling/depositing/gifting stockpile. Reuses already-cached snapshots. |
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
│        • NationProfitabilityService::getDailyConsumption     │
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
- Consumption math is read-only against `NationProfitabilityService`. We add a thin `getDailyConsumption(Nation): array{food, uranium}` accessor; we do not duplicate or fork the existing computation.
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

Concurrency: unique constraint on `nation_id` plus a try/catch on the unique-violation in the controller, converted to a `UserErrorException`.

No `pending_key` pattern: enrollment is instantaneous, not request/approval.

### 5.2 `growth_circle_distributions`

| column | type | notes |
|---|---|---|
| `id` | bigint PK | |
| `nation_id` | unsigned bigint, FK → `nations.id` | |
| `account_id` | unsigned bigint, FK → `accounts.id` | snapshot — preserved if account later deleted |
| `enrollment_id` | unsigned bigint, **nullable** FK → `growth_circle_enrollments.id` | nulled on enrollment deletion |
| `food` | decimal(20,2) | shipped this cycle |
| `uranium` | decimal(20,2) | shipped this cycle |
| `food_consumption_per_day` | decimal(20,4) | snapshot of profitability output |
| `uranium_consumption_per_day` | decimal(20,4) | snapshot of profitability output |
| `cycle_date` | date | |
| `created_at` | timestamp | |

Constraints:
- **Unique `(nation_id, cycle_date)`** — makes daily distribution idempotent across scheduler retries, server restarts mid-run, and admin manual re-runs.
- Index on `cycle_date` for admin history queries.

### 5.3 Settings (new keys via `SettingService`)

- `growth_circles.tax_id` — the P&W bracket ID with 100% retention across all resources. Admin must create the bracket in P&W and enter the ID here.
- `growth_circles.fallback_tax_id` — bracket assigned on disenroll if `previous_tax_id` is null or invalid (mirrors DD's fallback pattern).

### 5.4 Permissions (new entries in `config/permissions.php`)

- `view-growth-circles`
- `manage-growth-circles`

Diagnostic actions (manual distribution trigger, reapply bracket) additionally require the existing `view-diagnostic-info` permission per `CLAUDE.md` guidance.

## 6. Enrollment flow

### 6.1 Enroll

```
GrowthCircleService::enroll(Nation $nation, Account $account)

  guard: 5 eligibility gates
    – AllianceMembershipService::contains($nation->alliance_id)
    – $nation->alliance_position !== APPLICANT
    – $nation->vacation_mode_turns === 0
    – $nation->color !== 'beige'
    – $nation->num_cities > 0
  on failure → throw UserErrorException with the failing gate

  resolve previous_tax_id:
    if DirectDepositEnrollment exists:
       previousTaxId = ddEnrollment.previous_tax_id    // the *original*
       app(DirectDepositService)->disenroll($nation)   // restores bracket
    else if existing GrowthCircleEnrollment exists:
       previousTaxId = existing.previous_tax_id        // idempotent re-enroll
    else:
       previousTaxId = $nation->tax_id

  DB::transaction:
    GrowthCircleEnrollment::updateOrCreate(
      ['nation_id' => $nation->id],
      ['account_id', 'previous_tax_id', 'enrolled_at' => now()]
    )

  TaxBracketService → P&W: assign growth_circles.tax_id

  AuditLogger::recordAfterCommit('growth_circles.enrolled', actor, payload)
```

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

`DirectDepositService::enroll()` gains, at the top:

```
if ($gcEnrollment = GrowthCircleEnrollment::where('nation_id', $nation->id)->first()) {
    $previousTaxId = $gcEnrollment->previous_tax_id;
    app(GrowthCircleService::class)->disenroll($nation);
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

```php
try {
    DB::transaction(function () use ($enrollment, $cycleDate) {
        $nation = $enrollment->nation;

        // 1. Re-check 5 eligibility gates against fresh nation state.
        if (! $this->isEligible($nation)) {
            Log::info('growth_circles.skip', [...]);
            return;
        }

        // 2. Pull daily consumption from existing profitability snapshots.
        $consumption = app(NationProfitabilityService::class)
            ->getDailyConsumption($nation);
        if ($consumption === null) {
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

        $account->food    += $consumption['food'];
        $account->uranium += $consumption['uranium'];
        $account->save();

        GrowthCircleDistribution::create([
            'nation_id'                   => $nation->id,
            'account_id'                  => $account->id,
            'enrollment_id'               => $enrollment->id,
            'food'                        => $consumption['food'],
            'uranium'                     => $consumption['uranium'],
            'food_consumption_per_day'    => $consumption['food'],
            'uranium_consumption_per_day' => $consumption['uranium'],
            'cycle_date'                  => $cycleDate,
        ]);

        // 4. Emit alliance expense for finance reporting.
        event(new AllianceExpenseOccurred(
            AllianceFinanceData::forGrowthCircleDistribution($nation, $account, $consumption)
                ->toArray()
        ));
    });
} catch (QueryException $e) {
    if ($e->errorInfo[1] === 1062) {
        // Already distributed for this cycle_date — idempotent skip
        return;
    }
    throw $e;
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
public function getDailyConsumption(Nation $nation): ?array
```

Returns `['food' => float, 'uranium' => float]` from the most recent snapshot, or `null` if no snapshot exists. Thin read accessor — does not re-compute. Internals already exist (`food_cost_per_day`, plus uranium consumption already tracked).

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

Route::post('/admin/growth-circles/distribute-now',
    [Admin\GrowthCirclesController::class, 'distributeNow']
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

### 9.2 Enrolled in Growth Circles

- Status: "Enrolled — depositing into *Account Name*."
- Last distribution: date, food shipped, uranium shipped.
- Next distribution: "tomorrow ~03:00 UTC."
- Recent history: collapsible list, last 7 cycles.
- "Disenroll" button.

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

Page-level action:
- **Distribute now** — triggers `growth-circles:distribute` for the current `cycle_date`. Safe due to the unique `(nation_id, cycle_date)` constraint. Behind `manage-growth-circles` + `view-diagnostic-info`.

### 10.3 Distribution history (`/admin/growth-circles/history`)

Audit trail across all members. Columns:
- `cycle_date`, nation, account, food shipped, uranium shipped, `food_consumption_per_day` snapshot, `uranium_consumption_per_day` snapshot.

Filters: date range, nation, account.
Pagination: 50/page.
CSV export of current filter (matches existing tax/finance export patterns).

## 11. Audit logging

All write actions log via `AuditLogger`, using `recordAfterCommit()` for transaction-wrapped changes.

| Action | Event key |
|---|---|
| User enrolls | `growth_circles.enrolled` |
| User disenrolls | `growth_circles.disenrolled` |
| Auto-switch from DD → GC | `growth_circles.switched_from_dd` |
| Auto-switch from GC → DD | `direct_deposit.switched_from_growth_circles` |
| Admin force-disenroll | `growth_circles.admin_disenrolled` |
| Admin reapply bracket | `growth_circles.bracket_reapplied` |
| Admin manual distribute | `growth_circles.manual_distribution_triggered` |
| Settings updated | `growth_circles.settings_updated` |

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
| Unique violation `(nation_id, cycle_date)` | distribute | catch, treat as already-done, skip silently. |
| Account locked / missing | distribute | log error, skip member, continue cycle. |
| One member's distribution throws | distribute | per-member try/catch isolates it; cycle continues. |
| `growth_circles.tax_id` not configured | enroll | block with admin-facing error: "Growth Circles tax bracket is not configured." |
| P&W API down at scheduled time | scheduler | `->skip()` skips the cycle entirely; resumes next day. |
| Concurrent enrollment (double-click) | controller | unique constraint catches it; converted to friendly error. |

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
- `app/Services/NationProfitabilityService.php` — add `getDailyConsumption(Nation): ?array`.
- `app/Services/SettingService.php` — accessors for `growth_circles.tax_id` / `growth_circles.fallback_tax_id`.
- `resources/views/dashboard.blade.php` (or wherever the DD card lives) — include the Growth Circles card.
- `resources/views/admin/settings/*` — settings section partial.
- `app/DataTransferObjects/AllianceFinanceData.php` — `forGrowthCircleDistribution()` factory method (or equivalent existing pattern).

## 17. Open questions / future work

- **Abuse detection.** Computed-consumption math is structurally abuse-resistant for this version. If a member-with-no-farms exploit emerges, consider gating distribution on minimum farm count or military presence.
- **Multi-resource expansion.** The schema is food/uranium-specific by intent. If the program later expands to more resources, the migration adds columns and the service reads more keys from `NationProfitabilityService::getDailyConsumption`.
- **Distribution history retention.** No purge policy in this design; rows persist indefinitely. If table size becomes a concern, add a periodic archive command.
