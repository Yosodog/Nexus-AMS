# Growth Circles — Design Spec

**Date:** 2026-04-03
**Status:** Approved for implementation

## Overview

Growth Circles is an opt-in alliance program where members surrender 100% of their taxes in exchange for the alliance fully covering their food and uranium needs automatically. The goal is to accelerate city and infrastructure growth for participating nations by removing resource management overhead.

## Scope

- Automatic food and uranium distribution (scaled per city count)
- 100% tax bracket assignment on enrollment; bracket restoration on removal
- Abuse detection with automatic suspension and admin flagging
- Admin controls: global enable/disable toggle, per-city thresholds, source account, enrolled member management
- Member self-enrollment only; unenrollment is admin-only

**Out of scope:** Automatic city grant approval, automatic infrastructure funding, automatic distribution of any resource other than food and uranium.

---

## Tax Bracket

A dedicated P&W tax bracket must be created by an admin on the Politics & War side with 100% money and 100% resource tax rates. Its ID is stored in Nexus settings as `growth_circle_tax_id`.

`DirectDepositService::process()` already returns early for any `tax_id` that does not match `dd_tax_id`. Growth Circle tax records therefore flow through the standard tax pipeline untouched — 100% of the payment stays in the alliance bank. No changes to `DirectDepositService` are required.

---

## Data Model

### `growth_circle_enrollments`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `nation_id` | bigint FK | unique — one enrollment record per nation |
| `previous_tax_id` | int nullable | P&W tax bracket ID to restore on removal; nullable to handle edge cases where no prior bracket existed |
| `suspended` | bool | `false` by default |
| `suspended_at` | timestamp nullable | |
| `suspended_reason` | string nullable | human-readable reason set by abuse detection |
| `enrolled_at` | timestamp | |
| `created_at` / `updated_at` | timestamps | |

Unique index on `nation_id`. Unlike grants/loans, enrollment is a single-row-per-nation record (not a status-cycling workflow), so the `pending_key` pattern is not needed. `GrowthCircleService::enroll()` uses `updateOrCreate` on `nation_id` (same pattern as `DirectDepositEnrollment` in `DirectDepositService::enroll()`). The unique constraint prevents race-condition duplicates; `GrowthCircleService` catches `QueryException` on constraint violation and surfaces it as a `ValidationException`.

### `growth_circle_distributions`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `nation_id` | bigint FK | |
| `food_sent` | float | 0 if not sent this cycle |
| `uranium_sent` | float | 0 if not sent this cycle |
| `food_level_before` | float | nation's food level at time of check |
| `uranium_level_before` | float | nation's uranium level at time of check |
| `city_count` | int | city count used for threshold calculation |
| `created_at` | timestamp | |

This is an append-only log. The model sets `$timestamps = false` and defines only `const CREATED_AT = 'created_at'` to prevent Eloquent from trying to write `updated_at`. Index on `(nation_id, created_at)` for abuse detection lookups.

---

## Settings

Seven new keys added to the existing `SettingService` / admin settings screen:

| Key | Type | Description |
|---|---|---|
| `growth_circles_enabled` | bool | Global on/off toggle |
| `growth_circle_tax_id` | int | P&W tax bracket ID for 100% taxation |
| `growth_circle_fallback_tax_id` | int | Fallback P&W bracket to assign if previous bracket restore fails on removal |
| `growth_circle_source_account_id` | int | Alliance account food/uranium are sent from |
| `growth_circle_food_per_city` | int | Minimum food units to maintain per city |
| `growth_circle_uranium_per_city` | int | Minimum uranium units to maintain per city |
| `growth_circle_discord_channel_id` | string | Discord channel ID for abuse suspension alerts |

`SettingService` gets a `isGrowthCirclesEnabled(): bool` helper and static getters for each key, consistent with the existing `isAutoWithdrawEnabled()`, `getDiscordWarAlertChannelId()` etc. pattern.

---

## Enrollment Flow

### Member Opt-In (user-initiated)

1. Member visits their dashboard. If `isGrowthCirclesEnabled()` is true and they have no existing enrollment, an **Enroll in Growth Circles** action is shown.
2. On confirmation, `GrowthCircleService::enroll(Nation $nation)` is called:
   - Reads and stores `nation->tax_id` as `previous_tax_id`
   - Creates or updates `GrowthCircleEnrollment` via `updateOrCreate(['nation_id' => $nation->id], [...])` — catches `QueryException` on unique-constraint violation and throws `ValidationException`
   - Dispatches `TaxBracketService` to assign `growth_circle_tax_id` (same async queue pattern as `DirectDepositService::enroll()` — calls `$mutation->send()` which dispatches `AssignTaxBracket` job)
3. No unenroll option is shown on the member dashboard.

### Admin Removal

Admins with the `manage-growth-circles` permission can remove a nation from the Growth Circles admin screen.

`GrowthCircleService::remove(Nation $nation)`:
- Determines the bracket to restore: use `enrollment->previous_tax_id` if it is non-null; otherwise use `SettingService::getGrowthCircleFallbackTaxId()`
- Calls `TaxBracketService::send()` once with the resolved bracket ID
- Note: `TaxBracketService::send()` only dispatches `AssignTaxBracket` to the queue and never throws at the call site — do not wrap in try/catch expecting to catch a failure. Failures surface in the queue worker. The removal proceeds regardless.
- Deletes the enrollment record

---

## Distribution Command

**Command:** `growth-circles:distribute`
**Schedule:** Every 2 hours, `withoutOverlapping(110)`, `runInBackground()`, gated on `isGrowthCirclesEnabled()` and `PWHealthService::isUp()`

The command runs in two passes: first the distribution pass (send food/uranium to each nation), then the abuse detection pass. These are separate loops — abuse detection runs only after all distributions for the cycle are complete.

### Pass 1: Distribution

For each `GrowthCircleEnrollment` where `suspended = false`:

1. **Refresh resources** — if `nation->resources->updated_at` is older than 3 hours (same threshold as `AutoWithdrawService`), call `NationQueryService::getNationById()` and `Nation::updateFromAPI()`. If the refresh fails, skip this nation for the cycle (log warning, do not suspend).
2. **Calculate targets:**
   ```
   food_target    = nation->num_cities × growth_circle_food_per_city
   uranium_target = nation->num_cities × growth_circle_uranium_per_city
   ```
3. **Calculate deltas:**
   ```
   food_to_send    = max(0, food_target - current_food)
   uranium_to_send = max(0, uranium_target - current_uranium)
   ```
4. **Send if needed** — if either delta > 0:
   - Inside a `DB::transaction` with `lockForUpdate` on the source account:
     - Check available balance: `if ($sourceAccount->food < $food_to_send) clamp food_to_send to available`. Same for uranium. If both clamp to 0, log a warning, skip this nation, do not suspend.
     - Deduct `food_to_send` and `uranium_to_send` from the locked source account and save.
     - Call `TransactionService::createTransaction()` with:
       - `resources`: `['food' => $food_to_send, 'uranium' => $uranium_to_send]` (zero values omitted)
       - `nation_id`: `$nation->id`
       - `fromAccountId`: source account ID
       - `transactionType`: `'withdrawal'`
       - `isPending`: `true` — **must be `true`**; the `SendBank` job checks `is_pending` and exits early without sending if it is `false`
       - `requiresAdminApproval`: `false`
       - `note`: `'Growth Circle distribution'`
   - After the DB transaction commits, call `BankService::send($transaction)` to queue the actual P&W API send (same pattern as `AutoWithdrawService` lines 202–204 which calls `AccountService::dispatchWithdrawal()` only when `!$evaluation['requires_approval']`).
5. **Log** — create a `GrowthCircleDistribution` record **outside** the `DB::transaction` block, after it completes (or even when no resources were sent this cycle). Zero-send records serve as abuse detection checkpoints. Writing the log outside the transaction ensures nations that need no top-up are still tracked.

### Pass 2: Abuse Detection

Runs after Pass 1 completes. For each enrolled nation (including those that received zero distributions this cycle):

```
total_food_sent    = sum of food_sent from growth_circle_distributions in last 24 hours
total_uranium_sent = sum of uranium_sent from growth_circle_distributions in last 24 hours
```

Estimate maximum plausible consumption using per-city rates derived from game mechanics:

```
estimated_food_consumed    = nation->num_cities × food_consumption_rate_per_city × 24
estimated_uranium_consumed = nation->num_cities × uranium_consumption_rate_per_city × 24

expected_food_floor    = total_food_sent - estimated_food_consumed
expected_uranium_floor = total_uranium_sent - estimated_uranium_consumed
```

**Abuse threshold:** if `expected_food_floor > 0 AND current_food < expected_food_floor × 0.20`, OR `expected_uranium_floor > 0 AND current_uranium < expected_uranium_floor × 0.20`, the nation is flagged. The per-resource `expected_floor > 0` guards are sufficient — no separate outer guard is needed. A nation where both floors are zero or negative will simply not trigger either condition and will not be flagged.

- `enrollment->suspended = true`
- `enrollment->suspended_at = now()`
- `enrollment->suspended_reason = "Resource levels significantly below expected after distributions. Possible selling detected."`
- Send a Discord notification to `growth_circle_discord_channel_id` via `Notification::route(DiscordQueueChannel::class, 'discord-bot')` — same routing pattern as `BeigeAlertService`

Distributions to this nation stop until an admin clears the suspension via the admin screen.

---

## Permissions

Two new permissions added to `config/permissions.php`:

| Permission | Purpose |
|---|---|
| `view-growth-circles` | View enrolled members and distribution logs |
| `manage-growth-circles` | Remove members, clear suspensions, configure settings |

---

## Admin UI

### Settings Page (existing screen, new section)

- Toggle: Growth Circles enabled/disabled
- Input: Growth Circle Tax Bracket ID
- Input: Fallback Tax Bracket ID
- Input: Source Account (account selector)
- Input: Food per city (integer)
- Input: Uranium per city (integer)
- Input: Discord Channel ID for abuse alerts

### Growth Circles Admin Screen (new page, behind `view-growth-circles`)

- Table of enrolled nations: nation name, city count, enrolled date, suspension status
- Suspended nations highlighted with suspension reason; admins with `manage-growth-circles` can **Clear Suspension** (sets `suspended = false`, clears `suspended_at`/`suspended_reason`) or **Remove from Program** (calls `GrowthCircleService::remove()`)
- Distribution history per nation: last 30 days of `growth_circle_distributions` records, expandable row or modal

---

## Member UI

### Dashboard Card

- Visible when `isGrowthCirclesEnabled()` is true
- If not enrolled: **Enroll in Growth Circles** button with a brief description of the program (100% taxation, food/uranium covered automatically)
- If enrolled and active: status card showing enrollment date
- If enrolled and suspended: note that distributions are paused and to contact an admin
- No unenroll button under any circumstance

---

## Error Handling & Edge Cases

- **P&W API down:** Distribution command skips entirely via `PWHealthService::isUp()` gate — no partial distributions.
- **Resource refresh fails:** Skip that nation for the cycle, log a warning. Do not suspend.
- **Source account insufficient funds:** Clamp send amounts to available balance. If both clamp to zero, log a warning and skip the nation for the cycle. Do not suspend the nation — insufficient funds is an alliance-side problem, not member abuse.
- **Nation leaves alliance:** Not auto-detected in this iteration. Admin removes manually from the Growth Circles screen. Noted as a future improvement.
- **Nation already on DD bracket at enrollment time:** `previous_tax_id` stores their current bracket. Removing them from Growth Circles restores the DD bracket, which is correct — they would need to re-enroll in DD separately if desired.
- **Concurrent enrollment attempts:** `updateOrCreate` with unique index on `nation_id` prevents duplicates. `QueryException` on constraint violation is caught and re-thrown as `ValidationException`.
- **Discord channel not configured:** If `growth_circle_discord_channel_id` is empty, skip notification and log a warning. Do not fail the suspension.
