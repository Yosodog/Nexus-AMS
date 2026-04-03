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
| `nation_id` | bigint FK | unique — one active enrollment per nation |
| `previous_tax_id` | int | P&W tax bracket ID to restore on removal |
| `suspended` | bool | `false` by default |
| `suspended_at` | timestamp nullable | |
| `suspended_reason` | string nullable | human-readable reason set by abuse detection |
| `enrolled_at` | timestamp | |
| `created_at` / `updated_at` | timestamps | |

Unique index on `nation_id`.

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

Index on `(nation_id, created_at)` for abuse detection lookups.

---

## Settings

Five new keys added to the existing `SettingService` / admin settings screen:

| Key | Type | Description |
|---|---|---|
| `growth_circles_enabled` | bool | Global on/off toggle |
| `growth_circle_tax_id` | int | P&W tax bracket ID for 100% taxation |
| `growth_circle_source_account_id` | int | Alliance account food/uranium are sent from |
| `growth_circle_food_per_city` | int | Minimum food units to maintain per city |
| `growth_circle_uranium_per_city` | int | Minimum uranium units to maintain per city |

`SettingService` gets a `isGrowthCirclesEnabled(): bool` helper, consistent with `isAutoWithdrawEnabled()` and `isGrantApprovalsEnabled()`.

---

## Enrollment Flow

### Member Opt-In (user-initiated)

1. Member visits their dashboard. If `isGrowthCirclesEnabled()` is true and they have no existing enrollment, an **Enroll in Growth Circles** action is shown.
2. On confirmation, `GrowthCircleService::enroll(Nation $nation)` is called:
   - Reads and stores `nation->tax_id` as `previous_tax_id`
   - Creates `GrowthCircleEnrollment` record
   - Dispatches `TaxBracketService` to assign `growth_circle_tax_id` (same async pattern as `DirectDepositService::enroll()`)
3. No unenroll option is shown on the member dashboard.

### Admin Removal

Admins with the `manage-growth-circles` permission can remove a nation from the Growth Circles admin screen.

`GrowthCircleService::remove(Nation $nation)` :
- Restores the nation's previous tax bracket via `TaxBracketService` (same fallback logic as `DirectDepositService::disenroll()` — falls back to `growth_circle_tax_id` fallback setting if restoration fails)
- Deletes the enrollment record

---

## Distribution Command

**Command:** `growth-circles:distribute`
**Schedule:** Every 2 hours, `withoutOverlapping(110)`, `runInBackground()`, gated on `isGrowthCirclesEnabled()` and `PWHealthService::isUp()`

### Per-nation logic

For each `GrowthCircleEnrollment` where `suspended = false`:

1. **Refresh resources** — if `nation->resources->updated_at` is older than 3 hours (same threshold as `AutoWithdrawService`), call `NationQueryService::getNationById()` and `Nation::updateFromAPI()`.
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
4. **Send if needed** — if either delta > 0, build a `BankService` instance, set receiver to `nation->id`, set resources, call `send()` (queued, same as other withdrawals). Deduct from source account within a DB transaction.
5. **Log** — create a `GrowthCircleDistribution` record regardless of whether resources were sent (zero-send records serve as abuse detection checkpoints).

### Abuse Detection (runs at end of each distribution pass)

For each enrolled nation, look at `growth_circle_distributions` records from the last 24 hours:

```
total_food_sent    = sum of food_sent over window
total_uranium_sent = sum of uranium_sent over window
hours_in_window    = 24
```

Estimate minimum expected holdings using conservative per-city consumption rates (food and uranium consumption scales with city count and is knowable from game mechanics):

```
estimated_food_consumed    = nation->num_cities × food_consumption_rate_per_city × hours_in_window
estimated_uranium_consumed = nation->num_cities × uranium_consumption_rate_per_city × hours_in_window

expected_food_floor    = total_food_sent - estimated_food_consumed
expected_uranium_floor = total_uranium_sent - estimated_uranium_consumed
```

**Abuse threshold:** if `current_food < expected_food_floor × 0.20` OR `current_uranium < expected_uranium_floor × 0.20`, the nation is flagged:

- `enrollment->suspended = true`
- `enrollment->suspended_at = now()`
- `enrollment->suspended_reason = "Resource levels significantly below expected after distributions. Possible selling detected."`
- Admin notification dispatched (same notification channel used by other alert systems)

Distributions to this nation stop until an admin clears the suspension.

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
- Input: Growth Circle Tax Bracket ID (P&W bracket ID)
- Input: Source Account (account selector)
- Input: Food per city (integer)
- Input: Uranium per city (integer)

### Growth Circles Admin Screen (new page, behind `view-growth-circles`)

- Table of enrolled nations: nation name, city count, enrolled date, suspension status
- Suspended nations highlighted; admins with `manage-growth-circles` can **Clear Suspension** (re-activates distributions) or **Remove from Program** (restores tax bracket)
- Distribution history per nation (last 30 days, expandable row or modal)

---

## Member UI

### Dashboard Card

- Visible when `isGrowthCirclesEnabled()` is true
- If not enrolled: **Enroll in Growth Circles** button with a brief description of the program (100% taxation, food/uranium covered)
- If enrolled: status card showing enrollment date, current suspended state (if suspended, a note that distributions are paused and to contact an admin)
- No unenroll button under any circumstance

---

## Error Handling & Edge Cases

- **P&W API down:** Distribution command skips entirely via `PWHealthService::isUp()` gate — no partial distributions.
- **Source account insufficient funds:** `BankService` will fail the send; log a warning, skip that nation for the cycle, do not suspend them.
- **Nation leaves alliance:** The existing `AllianceMembershipService` check is not added to the distribution loop directly, but the admin can remove the nation. A follow-up improvement could auto-remove non-members.
- **Nation already on DD bracket at enrollment time:** `previous_tax_id` is stored as whatever bracket they currently have. If they were on DD, disenrolling from Growth Circles would restore the DD bracket, which is correct behavior.
- **Concurrent enrollment attempts:** Unique index on `growth_circle_enrollments.nation_id` prevents duplicate enrollments; service catches constraint violations and surfaces as a validation error.
