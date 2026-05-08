# Growth Circles Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Growth Circles feature: an opt-in 100%-tax member program that ships each enrolled nation a daily ledger credit of food and uranium equal to that nation's daily shortfall (`max(0, -resource_profit_per_day)`).

**Architecture:** Parallel service mirroring DirectDeposit's enroll/disenroll/audit shape, with a daily scheduled command that reads from `nation_profitability_snapshots` and credits resources to a member-selected internal `Account`. The two programs auto-switch on enrollment while preserving the original pre-program tax bracket.

**Tech Stack:** Laravel 12, PHP 8.4, MySQL/MariaDB, Tailwind+DaisyUI (user side), Bootstrap+AdminLTE (admin side).

**Spec:** `docs/superpowers/specs/2026-05-07-growth-circles-design.md`

**Project policy notes:**
- **NO automated tests.** Per `CLAUDE.md`, this project does not use automated tests. Each task uses manual verification (artisan tinker, browser, log inspection) instead. Do not write or run PHPUnit/Pest tests.
- **Run Pint after every PHP change.** `./vendor/bin/pint` before committing.
- **`config('key')` only.** Never use `env()` outside config files.
- **Form Requests for validation.** Never inline.
- **Constructor property promotion.** Always use `public function __construct(public FooService $foo) {}` style.
- **Explicit return types and typed parameters.** Always.

---

## Chunk 1: Schema, Models, Permissions, and Settings

This chunk establishes the data foundations. After it lands the database has the new tables, models, permissions are registered, and `SettingService` has accessors for the two new tax-id keys. No behavior is wired yet — the application still runs exactly as before.

### Task 1.1: Create the `growth_circle_enrollments` migration

**Files:**
- Create: `database/migrations/2026_05_07_000001_create_growth_circle_enrollments_table.php`

(Use the timestamp suffix from `date +%Y_%m_%d_%H%M%S` at run time; `000001` shown here is a placeholder — Laravel's `make:migration` will produce the real timestamp.)

- [ ] **Step 1: Generate the migration file**

Run:
```bash
php artisan make:migration create_growth_circle_enrollments_table
```
Expected: file appears under `database/migrations/<today>_create_growth_circle_enrollments_table.php`.

- [ ] **Step 2: Replace generated content with the schema**

Open the new file and replace its body with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_circle_enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nation_id')->unique();
            $table->unsignedBigInteger('account_id');
            $table->integer('previous_tax_id')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamps();

            $table->foreign('nation_id')->references('id')->on('nations')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_circle_enrollments');
    }
};
```

- [ ] **Step 3: Run the migration**

Run:
```bash
php artisan migrate
```
Expected: `INFO  Running migrations.` followed by `<filename> ............ DONE`.

- [ ] **Step 4: Verify schema in tinker**

Run:
```bash
php artisan tinker --execute="dump(\Schema::getColumnListing('growth_circle_enrollments'));"
```
Expected output includes `id, nation_id, account_id, previous_tax_id, enrolled_at, created_at, updated_at`.

- [ ] **Step 5: Format and commit**

```bash
./vendor/bin/pint database/migrations/
git add database/migrations/*_create_growth_circle_enrollments_table.php
git commit -m "Add growth_circle_enrollments migration"
```

### Task 1.2: Create the `growth_circle_distributions` migration

**Files:**
- Create: `database/migrations/2026_05_07_000002_create_growth_circle_distributions_table.php`

- [ ] **Step 1: Generate the migration file**

Run:
```bash
php artisan make:migration create_growth_circle_distributions_table
```

- [ ] **Step 2: Replace generated content with the schema**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_circle_distributions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nation_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->decimal('food', 20, 2)->default(0);
            $table->decimal('uranium', 20, 2)->default(0);
            $table->date('cycle_date');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('nation_id')->references('id')->on('nations')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts');
            $table->foreign('enrollment_id')
                ->references('id')
                ->on('growth_circle_enrollments')
                ->nullOnDelete();

            $table->unique(['nation_id', 'cycle_date'], 'gc_distribution_nation_cycle_unique');
            $table->index('cycle_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_circle_distributions');
    }
};
```

- [ ] **Step 3: Run the migration**

```bash
php artisan migrate
```
Expected: `<filename> ........... DONE`.

- [ ] **Step 4: Verify the unique index exists**

```bash
php artisan tinker --execute="dump(\DB::select('SHOW INDEXES FROM growth_circle_distributions WHERE Key_name = ?', ['gc_distribution_nation_cycle_unique']));"
```
Expected: two rows (one per indexed column: nation_id, cycle_date), both with `Non_unique = 0`.

- [ ] **Step 5: Format and commit**

```bash
./vendor/bin/pint database/migrations/
git add database/migrations/*_create_growth_circle_distributions_table.php
git commit -m "Add growth_circle_distributions migration with unique cycle key"
```

### Task 1.3: Create the `GrowthCircleEnrollment` model

**Files:**
- Create: `app/Models/GrowthCircleEnrollment.php`

- [ ] **Step 1: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GrowthCircleEnrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'nation_id',
        'account_id',
        'previous_tax_id',
        'enrolled_at',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
        'previous_tax_id' => 'integer',
    ];

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(GrowthCircleDistribution::class, 'enrollment_id');
    }
}
```

- [ ] **Step 2: Verify Eloquent finds the model**

```bash
php artisan tinker --execute="dump(\App\Models\GrowthCircleEnrollment::query()->count());"
```
Expected: `int(0)` (table empty, but model resolves and the query runs without error).

- [ ] **Step 3: Format and commit**

```bash
./vendor/bin/pint app/Models/GrowthCircleEnrollment.php
git add app/Models/GrowthCircleEnrollment.php
git commit -m "Add GrowthCircleEnrollment model"
```

### Task 1.4: Create the `GrowthCircleDistribution` model

**Files:**
- Create: `app/Models/GrowthCircleDistribution.php`

- [ ] **Step 1: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrowthCircleDistribution extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'nation_id',
        'account_id',
        'enrollment_id',
        'food',
        'uranium',
        'cycle_date',
    ];

    protected $casts = [
        'food' => 'float',
        'uranium' => 'float',
        'cycle_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(GrowthCircleEnrollment::class, 'enrollment_id');
    }
}
```

Note: the migration uses `useCurrent()` for `created_at` only; there is no `updated_at`. Hence `public $timestamps = false`.

- [ ] **Step 2: Verify the model resolves**

```bash
php artisan tinker --execute="dump(\App\Models\GrowthCircleDistribution::query()->count());"
```
Expected: `int(0)`.

- [ ] **Step 3: Format and commit**

```bash
./vendor/bin/pint app/Models/GrowthCircleDistribution.php
git add app/Models/GrowthCircleDistribution.php
git commit -m "Add GrowthCircleDistribution model"
```

### Task 1.5: Add permissions to `config/permissions.php`

**Files:**
- Modify: `config/permissions.php`

- [ ] **Step 1: Add the two new permission strings**

Open `config/permissions.php`. The file is a flat array of strings. Add these two lines somewhere alphabetically/contextually appropriate (the existing file groups DD permissions together — put the new ones near them):

```php
    'view-growth-circles',
    'manage-growth-circles',
```

- [ ] **Step 2: Verify the array reads back the new entries**

```bash
php artisan tinker --execute="dump(in_array('view-growth-circles', config('permissions')));"
```
Expected: `bool(true)`.

```bash
php artisan tinker --execute="dump(in_array('manage-growth-circles', config('permissions')));"
```
Expected: `bool(true)`.

- [ ] **Step 3: Format and commit**

```bash
./vendor/bin/pint config/permissions.php
git add config/permissions.php
git commit -m "Add view-growth-circles and manage-growth-circles permissions"
```

### Task 1.6: Add `SettingService` accessors for Growth Circles tax IDs

**Files:**
- Modify: `app/Services/SettingService.php`

The DD pattern lives at `app/Services/SettingService.php:416-455`. Mirror it: getter, setter, `isEnabled` check.

- [ ] **Step 1: Add four new methods after the existing DD block**

Find the block ending with `isDirectDepositEnabled()` (~line 455). Immediately after that closing brace, add:

```php
    public static function getGrowthCirclesTaxId(): int
    {
        $value = self::getValue('growth_circles_tax_id');

        if (is_null($value)) {
            self::setGrowthCirclesTaxId(0);

            return 0;
        }

        return (int) $value;
    }

    public static function setGrowthCirclesTaxId(int $taxId): void
    {
        self::setValue('growth_circles_tax_id', $taxId);
    }

    public static function getGrowthCirclesFallbackTaxId(): int
    {
        $value = self::getValue('growth_circles_fallback_tax_id');

        if (is_null($value)) {
            self::setGrowthCirclesFallbackTaxId(0);

            return 0;
        }

        return (int) $value;
    }

    public static function setGrowthCirclesFallbackTaxId(int $taxId): void
    {
        self::setValue('growth_circles_fallback_tax_id', $taxId);
    }

    public static function isGrowthCirclesEnabled(): bool
    {
        return self::getGrowthCirclesTaxId() > 0;
    }
```

Note: matching DD's `int` (not `?int`) return — the spec hinted `?int` but the existing pattern uses `int` defaulting to 0 with an `isEnabled()` check. Following the established pattern.

- [ ] **Step 2: Verify the accessors resolve**

```bash
php artisan tinker --execute="dump(\App\Services\SettingService::getGrowthCirclesTaxId());"
```
Expected: `int(0)` and a row appears in `settings` for `growth_circles_tax_id`.

```bash
php artisan tinker --execute="dump(\App\Services\SettingService::isGrowthCirclesEnabled());"
```
Expected: `bool(false)`.

```bash
php artisan tinker --execute="\App\Services\SettingService::setGrowthCirclesTaxId(99); dump(\App\Services\SettingService::getGrowthCirclesTaxId(), \App\Services\SettingService::isGrowthCirclesEnabled()); \App\Services\SettingService::setGrowthCirclesTaxId(0);"
```
Expected: `int(99)` then `bool(true)`. The third call resets so the rest of the plan starts from a clean state.

- [ ] **Step 3: Format and commit**

```bash
./vendor/bin/pint app/Services/SettingService.php
git add app/Services/SettingService.php
git commit -m "Add Growth Circles tax-id accessors to SettingService"
```

### Task 1.7: Sanity check the chunk

- [ ] **Step 1: Run any existing migrations + boot the app**

```bash
php artisan migrate:status
```
Expected: both new migrations appear with `Yes` (Ran) in the `Ran?` column.

- [ ] **Step 2: Make sure the app boots**

```bash
php artisan about
```
Expected: command completes without errors. The `about` output is informational only — it confirms config loading and DB connectivity work after the schema changes.

- [ ] **Step 3: Confirm clean working tree**

```bash
git status
```
Expected: `nothing to commit, working tree clean`.

End of Chunk 1.

> **Naming note (applies to all chunks):** The spec uses dotted-form names like `growth_circles.tax_id` for readability. The actual `settings` table stores keys with underscores (`growth_circles_tax_id`), matching the existing DD pattern (`dd_tax_id`). The plan and code use the underscore form everywhere; the dotted form is descriptive shorthand only.

---

## Chunk 2: Service Layer

This chunk implements all the business logic: the `getDailyResourceShortfall` accessor on `NationProfitabilityService`, an `AllianceFinanceData` factory for distributions, the new `GrowthCircleService` (enroll, disenroll, daily distribution, eligibility), and the symmetric ~5-line modification to `DirectDepositService::enroll()` for two-way auto-switch. After this chunk the system is fully exercisable from tinker but has no UI or scheduler entry yet.

### Task 2.1: Add `getDailyResourceShortfall` to `NationProfitabilityService`

**Files:**
- Modify: `app/Services/NationProfitabilityService.php`

The new method reads the latest `NationProfitabilitySnapshot` for the nation and returns the food/uranium shortfall (consumption above production) as `max(0, -value)` for each resource. Net producers receive 0; net consumers get their daily deficit.

- [ ] **Step 1: Locate the class and add the method**

Open `app/Services/NationProfitabilityService.php`. Add the following public method to the class (placement: directly after the existing `__construct` or a similarly public-facing read method — anywhere in the public section is fine).

```php
    /**
     * Daily food and uranium shortfall (consumption above production) for the
     * given nation, read from its latest profitability snapshot.
     *
     * Net producers (production >= consumption) receive 0 for that resource.
     * Net consumers receive their daily deficit (a positive float).
     *
     * @return array{food: float, uranium: float}|null
     *         Null if no snapshot exists for this nation.
     */
    public function getDailyResourceShortfall(\App\Models\Nation $nation): ?array
    {
        $snapshot = \App\Models\NationProfitabilitySnapshot::query()
            ->where('nation_id', $nation->id)
            ->latest('calculated_at')
            ->first();

        if (! $snapshot) {
            return null;
        }

        $perDay = $snapshot->resource_profit_per_day ?? [];

        return [
            'food' => max(0.0, -(float) ($perDay['food'] ?? 0.0)),
            'uranium' => max(0.0, -(float) ($perDay['uranium'] ?? 0.0)),
        ];
    }
```

(If `Nation` and `NationProfitabilitySnapshot` are already imported at the top of the file, drop the leading `\App\Models\` and use the short names.)

- [ ] **Step 2: Verify against a real snapshot**

```bash
php artisan tinker --execute="\$n = \App\Models\Nation::query()->whereHas('profitabilitySnapshot')->orWhere(function(\$q){\$q->whereExists(function(\$s){\$s->select(\DB::raw(1))->from('nation_profitability_snapshots')->whereColumn('nation_profitability_snapshots.nation_id','nations.id');});})->first(); dump(app(\App\Services\NationProfitabilityService::class)->getDailyResourceShortfall(\$n));"
```

Expected: an `array` of `['food' => float, 'uranium' => float]` if at least one snapshot exists, with both values >= 0.

If no snapshot exists in the dev DB:
```bash
php artisan tinker --execute="dump(app(\App\Services\NationProfitabilityService::class)->getDailyResourceShortfall(\App\Models\Nation::query()->first()));"
```
Expected: `NULL` (correct behavior when no snapshot exists).

- [ ] **Step 3: Format and commit**

```bash
./vendor/bin/pint app/Services/NationProfitabilityService.php
git add app/Services/NationProfitabilityService.php
git commit -m "Add getDailyResourceShortfall accessor to NationProfitabilityService"
```

### Task 2.2: Add `forGrowthCircleDistribution` factory to `AllianceFinanceData`

**Files:**
- Modify: `app/DataTransferObjects/AllianceFinanceData.php`

A static factory method to keep the call site in `GrowthCircleService` clean.

- [ ] **Step 1: Add the factory method to the class**

Open `app/DataTransferObjects/AllianceFinanceData.php`. After the existing `fromArray()` method, add:

```php
    public static function forGrowthCircleDistribution(
        \App\Models\Nation $nation,
        \App\Models\Account $account,
        \App\Models\GrowthCircleDistribution $distribution,
        float $food,
        float $uranium,
    ): self {
        return new self(
            direction: \App\Models\AllianceFinanceEntry::DIRECTION_EXPENSE,
            category: 'growth_circles_distribution',
            description: "Growth Circles distribution for {$nation->nation_name}",
            date: \Illuminate\Support\Carbon::parse($distribution->cycle_date),
            nationId: $nation->id,
            accountId: $account->id,
            source: $distribution,
            food: $food,
            uranium: $uranium,
            meta: [
                'cycle_date' => $distribution->cycle_date instanceof \Carbon\CarbonInterface
                    ? $distribution->cycle_date->toDateString()
                    : (string) $distribution->cycle_date,
            ],
        );
    }
```

(If the imports for `Nation`, `Account`, `GrowthCircleDistribution`, `AllianceFinanceEntry`, and `Carbon` already exist in the file, use the short names.)

- [ ] **Step 2: Verify the factory resolves**

```bash
php artisan tinker --execute="\$n = \App\Models\Nation::query()->first(); \$a = \App\Models\Account::query()->where('nation_id', \$n->id)->first() ?? new \App\Models\Account(['id'=>0,'nation_id'=>\$n->id]); \$d = new \App\Models\GrowthCircleDistribution(['nation_id'=>\$n->id,'account_id'=>\$a->id ?? 0,'food'=>5.0,'uranium'=>2.0,'cycle_date'=>now()->toDateString()]); dump(\App\DataTransferObjects\AllianceFinanceData::forGrowthCircleDistribution(\$n, \$a, \$d, 5.0, 2.0)->toArray());"
```

Expected: an array with `direction => 'expense'` (or whatever `AllianceFinanceEntry::DIRECTION_EXPENSE` is), `category => 'growth_circles_distribution'`, `food => 5.0`, `uranium => 2.0`, plus the standard fields.

- [ ] **Step 3: Format and commit**

```bash
./vendor/bin/pint app/DataTransferObjects/AllianceFinanceData.php
git add app/DataTransferObjects/AllianceFinanceData.php
git commit -m "Add forGrowthCircleDistribution factory to AllianceFinanceData"
```

### Task 2.3: Scaffold `GrowthCircleService` with eligibility helper

**Files:**
- Create: `app/Services/GrowthCircleService.php`

Initial skeleton: constructor with DI, plus `isEligible($nation): array` helper. Enroll/disenroll/distribution come in later tasks.

- [ ] **Step 1: Write the skeleton**

```php
<?php

namespace App\Services;

use App\Enums\AlliancePositionEnum;
use App\Models\Nation;

class GrowthCircleService
{
    public function __construct(
        protected AllianceMembershipService $membershipService,
    ) {}

    /**
     * Evaluate the five eligibility gates for Growth Circles.
     *
     * Both enrollment and per-cycle distribution use this method, so
     * a member who later loses eligibility is paused (not auto-disenrolled).
     *
     * @return array{eligible: bool, reason: ?string}
     */
    public function evaluateEligibility(Nation $nation): array
    {
        if (! $this->membershipService->contains((int) $nation->alliance_id)) {
            return ['eligible' => false, 'reason' => 'Nation is not in the alliance group.'];
        }

        if (($nation->alliance_position ?? null) === AlliancePositionEnum::APPLICANT->value) {
            return ['eligible' => false, 'reason' => 'Applicants are not eligible for Growth Circles.'];
        }

        if ((int) ($nation->vacation_mode_turns ?? 0) > 0) {
            return ['eligible' => false, 'reason' => 'Not available while in vacation mode.'];
        }

        if (strtolower((string) ($nation->color ?? '')) === 'beige') {
            return ['eligible' => false, 'reason' => 'Not available while in beige.'];
        }

        if ((int) ($nation->num_cities ?? 0) <= 0) {
            return ['eligible' => false, 'reason' => 'Nation has no cities.'];
        }

        return ['eligible' => true, 'reason' => null];
    }
}
```

Notes for the implementer:
- `AlliancePositionEnum::APPLICANT->value` matches the comparison style used in `RebuildingService::evaluateEligibility` — verify the enum exists at `app/Enums/AlliancePositionEnum.php` and has an `APPLICANT` case.
- The `color` check tolerates either lowercase or capitalized values from the API.

- [ ] **Step 2: Smoke-test the helper in tinker**

```bash
php artisan tinker --execute="\$n = \App\Models\Nation::query()->first(); dump(app(\App\Services\GrowthCircleService::class)->evaluateEligibility(\$n));"
```

Expected: an array with `eligible` (bool) and `reason` (string or null). The truth value depends on the test nation's state; what matters is the call doesn't throw.

- [ ] **Step 3: Format and commit**

```bash
./vendor/bin/pint app/Services/GrowthCircleService.php
git add app/Services/GrowthCircleService.php
git commit -m "Scaffold GrowthCircleService with eligibility helper"
```

### Task 2.4: Add the `enroll()` method

**Files:**
- Modify: `app/Services/GrowthCircleService.php`

Implements the full enroll flow: eligibility gates, capture-then-mutate `previous_tax_id` resolution including auto-switch from DD, persist enrollment, assign new tax bracket, audit log.

- [ ] **Step 1: Add the method (and required imports)**

Add these `use` statements near the top of the file (preserve existing ones):

```php
use App\Exceptions\UserErrorException;
use App\Models\Account;
use App\Models\DirectDepositEnrollment;
use App\Models\GrowthCircleEnrollment;
use Illuminate\Support\Facades\DB;
```

Add the method to the class:

```php
    public function enroll(Nation $nation, Account $account): void
    {
        // Step 1: eligibility gates run first.
        $eligibility = $this->evaluateEligibility($nation);
        if (! $eligibility['eligible']) {
            throw new UserErrorException($eligibility['reason']);
        }

        // Defense-in-depth: account must belong to the enrolling nation.
        if ((int) $account->nation_id !== (int) $nation->id) {
            throw new UserErrorException('Selected account does not belong to your nation.');
        }

        $taxId = SettingService::getGrowthCirclesTaxId();
        if ($taxId <= 0) {
            throw new UserErrorException('Growth Circles is not configured. Contact an admin.');
        }

        // Step 2: capture previous_tax_id BEFORE any disenroll side effect.
        if ($ddEnrollment = DirectDepositEnrollment::query()->where('nation_id', $nation->id)->first()) {
            $previousTaxId = (int) $ddEnrollment->previous_tax_id;     // capture FIRST
            app(DirectDepositService::class)->disenroll($nation);      // then mutate
            $auditAction = 'switched_from_dd';
        } elseif ($existing = GrowthCircleEnrollment::query()->where('nation_id', $nation->id)->first()) {
            $previousTaxId = (int) $existing->previous_tax_id;          // idempotent re-enroll
            $auditAction = 'enrolled';
        } else {
            $previousTaxId = (int) $nation->tax_id;
            $auditAction = 'enrolled';
        }

        // Step 3: persist enrollment.
        $enrollment = DB::transaction(function () use ($nation, $account, $previousTaxId): GrowthCircleEnrollment {
            return GrowthCircleEnrollment::query()->updateOrCreate(
                ['nation_id' => $nation->id],
                [
                    'account_id' => $account->id,
                    'previous_tax_id' => $previousTaxId,
                    'enrolled_at' => now(),
                ],
            );
        });

        // Step 4: assign new bracket in P&W.
        $mutation = new TaxBracketService;
        $mutation->id = $taxId;
        $mutation->target_id = (int) $nation->id;
        $mutation->send();

        // Step 5: audit.
        app(AuditLogger::class)->recordAfterCommit(
            category: 'growth_circles',
            action: $auditAction,
            subject: $enrollment,
            context: [
                'data' => [
                    'nation_id' => $nation->id,
                    'account_id' => $account->id,
                    'previous_tax_id' => $previousTaxId,
                    'new_tax_id' => $taxId,
                ],
            ],
            message: $auditAction === 'switched_from_dd'
                ? "Switched nation {$nation->nation_name} from DirectDeposit to Growth Circles."
                : "Enrolled nation {$nation->nation_name} in Growth Circles.",
        );
    }
```

- [ ] **Step 2: Smoke-test the enroll path**

Pre-req: a P&W tax bracket exists in your dev environment to use as the Growth Circles bracket. Set it (any positive int will work for tinker; the `TaxBracketService::send()` call dispatches an async job that will fail or no-op against a fake P&W env — that's fine for testing the local DB write):

```bash
php artisan tinker --execute="\App\Services\SettingService::setGrowthCirclesTaxId(999);"
```

Then invoke enroll on a test nation that owns at least one account and currently passes the eligibility gates:

```bash
php artisan tinker --execute="\$n = \App\Models\Nation::query()->where('num_cities', '>', 0)->first(); \$a = \App\Models\Account::query()->where('nation_id', \$n->id)->first() ?? \App\Services\AccountService::createAccount(\$n->id, 'Test'); app(\App\Services\GrowthCircleService::class)->enroll(\$n, \$a); dump(\App\Models\GrowthCircleEnrollment::query()->where('nation_id', \$n->id)->first()->toArray());"
```

Expected: a `GrowthCircleEnrollment` row appears with the expected `account_id`, a `previous_tax_id`, and an `enrolled_at` timestamp.

If `evaluateEligibility` is hitting a gate, find a different test nation. The dump should show the inserted row.

Clean up so the rest of the plan starts fresh. Re-fetch the nation explicitly — `$n` does not persist across `tinker --execute` invocations:
```bash
php artisan tinker --execute="\App\Models\GrowthCircleEnrollment::query()->where('nation_id', \App\Models\Nation::query()->where('num_cities', '>', 0)->value('id'))->delete(); \App\Services\SettingService::setGrowthCirclesTaxId(0);"
```

- [ ] **Step 3: Format and commit**

```bash
./vendor/bin/pint app/Services/GrowthCircleService.php
git add app/Services/GrowthCircleService.php
git commit -m "Add enroll method to GrowthCircleService"
```

### Task 2.5: Add the `disenroll()` method

**Files:**
- Modify: `app/Services/GrowthCircleService.php`

Mirrors `DirectDepositService::disenroll()`: assigns the previous bracket via `TaxBracketService`, falls back on failure, then deletes the row regardless. The fallback-then-delete behavior is intentional — leaving a dangling enrollment row on a P&W API failure would freeze the user out of the program.

- [ ] **Step 1: Add the method**

Add to `GrowthCircleService` (after `enroll`):

```php
    public function disenroll(Nation $nation): void
    {
        $enrollment = GrowthCircleEnrollment::query()->where('nation_id', $nation->id)->first();
        if (! $enrollment) {
            return; // idempotent
        }

        $targetTaxId = (int) $enrollment->previous_tax_id;
        $fallbackTaxId = SettingService::getGrowthCirclesFallbackTaxId();

        try {
            $mutation = new TaxBracketService;
            $mutation->id = $targetTaxId > 0 ? $targetTaxId : $fallbackTaxId;
            $mutation->target_id = (int) $nation->id;
            $mutation->send();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                "GrowthCircles: failed to assign previous tax ID {$targetTaxId} for nation {$nation->id}, retrying with fallback {$fallbackTaxId}.",
                ['exception' => $e->getMessage()],
            );
            try {
                $fallbackMutation = new TaxBracketService;
                $fallbackMutation->id = $fallbackTaxId;
                $fallbackMutation->target_id = (int) $nation->id;
                $fallbackMutation->send();
            } catch (\Throwable $fallbackException) {
                \Illuminate\Support\Facades\Log::error(
                    "GrowthCircles: fallback tax-id assign also failed for nation {$nation->id}; deleting enrollment row anyway.",
                    ['exception' => $fallbackException->getMessage()],
                );
            }
        }

        $enrollment->delete();

        app(AuditLogger::class)->recordAfterCommit(
            category: 'growth_circles',
            action: 'disenrolled',
            subject: $enrollment,
            context: [
                'data' => [
                    'nation_id' => $nation->id,
                    'restored_tax_id' => $targetTaxId,
                ],
            ],
            message: "Disenrolled nation {$nation->nation_name} from Growth Circles.",
        );
    }
```

- [ ] **Step 2: Smoke-test the disenroll path**

```bash
php artisan tinker --execute="\App\Services\SettingService::setGrowthCirclesTaxId(999); \App\Services\SettingService::setGrowthCirclesFallbackTaxId(1); \$n = \App\Models\Nation::query()->where('num_cities', '>', 0)->first(); \$a = \App\Models\Account::query()->where('nation_id', \$n->id)->first(); app(\App\Services\GrowthCircleService::class)->enroll(\$n, \$a); dump('enrolled', \App\Models\GrowthCircleEnrollment::query()->where('nation_id', \$n->id)->exists()); app(\App\Services\GrowthCircleService::class)->disenroll(\$n); dump('disenrolled', \App\Models\GrowthCircleEnrollment::query()->where('nation_id', \$n->id)->exists()); \App\Services\SettingService::setGrowthCirclesTaxId(0); \App\Services\SettingService::setGrowthCirclesFallbackTaxId(0);"
```

Expected: `enrolled` followed by `bool(true)`, then `disenrolled` followed by `bool(false)`.

- [ ] **Step 3: Format and commit**

```bash
./vendor/bin/pint app/Services/GrowthCircleService.php
git add app/Services/GrowthCircleService.php
git commit -m "Add disenroll method to GrowthCircleService"
```

### Task 2.6: Add `runDailyDistribution()` and `distributeOne()`

**Files:**
- Modify: `app/Services/GrowthCircleService.php`

The daily-distribution loop. Per-member work is wrapped in its own try/catch + DB transaction so one bad nation cannot kill the cycle. Idempotency is enforced by the `(nation_id, cycle_date)` unique constraint — duplicate runs raise `UniqueConstraintViolationException` which is caught and treated as already-done.

- [ ] **Step 1: Add the imports**

If not already imported, add at the top of the file:

```php
use App\DataTransferObjects\AllianceFinanceData;
use App\Events\AllianceExpenseOccurred;
use App\Models\GrowthCircleDistribution;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;
```

- [ ] **Step 2: Add the public entry point**

```php
    /**
     * Run the daily distribution loop for every enrolled nation.
     * Returns counts of distributed / skipped / failed members for logging.
     *
     * @return array{distributed: int, skipped: int, failed: int}
     */
    public function runDailyDistribution(?string $cycleDate = null): array
    {
        $cycleDate ??= Carbon::now('UTC')->toDateString();
        $counts = ['distributed' => 0, 'skipped' => 0, 'failed' => 0];

        GrowthCircleEnrollment::query()
            ->with(['nation', 'account'])
            ->chunkById(200, function ($enrollments) use ($cycleDate, &$counts): void {
                foreach ($enrollments as $enrollment) {
                    $outcome = $this->distributeOne($enrollment, $cycleDate);
                    $counts[$outcome]++;
                }
            });

        return $counts;
    }
```

- [ ] **Step 3: Add the per-member helper**

```php
    /**
     * Process one enrollment for one cycle. Returns the outcome bucket name
     * so the caller can tally counts.
     *
     * @return 'distributed'|'skipped'|'failed'
     */
    protected function distributeOne(GrowthCircleEnrollment $enrollment, string $cycleDate): string
    {
        $nation = $enrollment->nation;
        if (! $nation) {
            Log::warning('growth_circles.skip', [
                'reason' => 'nation_missing',
                'enrollment_id' => $enrollment->id,
            ]);

            return 'skipped';
        }

        try {
            $result = DB::transaction(function () use ($enrollment, $nation, $cycleDate): string {
                $eligibility = $this->evaluateEligibility($nation);
                if (! $eligibility['eligible']) {
                    Log::info('growth_circles.skip', [
                        'nation_id' => $nation->id,
                        'reason' => $eligibility['reason'],
                        'cycle_date' => $cycleDate,
                    ]);

                    return 'skipped';
                }

                $shortfall = app(NationProfitabilityService::class)->getDailyResourceShortfall($nation);
                if ($shortfall === null) {
                    Log::warning('growth_circles.no_snapshot', [
                        'nation_id' => $nation->id,
                        'cycle_date' => $cycleDate,
                    ]);

                    return 'skipped';
                }

                if ($shortfall['food'] <= 0.0 && $shortfall['uranium'] <= 0.0) {
                    Log::info('growth_circles.no_shortfall', [
                        'nation_id' => $nation->id,
                        'cycle_date' => $cycleDate,
                    ]);

                    return 'skipped';
                }

                $account = Account::query()
                    ->whereKey($enrollment->account_id)
                    ->lockForUpdate()
                    ->first();

                if (! $account) {
                    Log::error('growth_circles.account_missing', [
                        'nation_id' => $nation->id,
                        'enrollment_id' => $enrollment->id,
                    ]);

                    return 'skipped';
                }

                // The account credit is performed here, NOT by the
                // AllianceExpenseOccurred listener. The listener
                // (RecordAllianceExpense) writes only an
                // AllianceFinanceEntry for reporting; it does not touch
                // Account balances. This matches DirectDepositService::process.
                $account->food += $shortfall['food'];
                $account->uranium += $shortfall['uranium'];
                $account->save();

                $distribution = GrowthCircleDistribution::query()->create([
                    'nation_id' => $nation->id,
                    'account_id' => $account->id,
                    'enrollment_id' => $enrollment->id,
                    'food' => $shortfall['food'],
                    'uranium' => $shortfall['uranium'],
                    'cycle_date' => $cycleDate,
                ]);

                event(new AllianceExpenseOccurred(
                    AllianceFinanceData::forGrowthCircleDistribution(
                        $nation,
                        $account,
                        $distribution,
                        $shortfall['food'],
                        $shortfall['uranium'],
                    )->toArray()
                ));

                return 'distributed';
            });

            return $result;
        } catch (UniqueConstraintViolationException) {
            // Already distributed for this cycle_date — idempotent skip.
            // Transaction has already rolled back at this point.
            return 'skipped';
        } catch (Throwable $e) {
            Log::error('growth_circles.distribute.failed', [
                'nation_id' => $enrollment->nation_id,
                'enrollment_id' => $enrollment->id,
                'message' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }
```

- [ ] **Step 4: Smoke-test the loop with no enrollments**

```bash
php artisan tinker --execute="dump(app(\App\Services\GrowthCircleService::class)->runDailyDistribution());"
```
Expected: `array(3) { ["distributed"]=> int(0) ["skipped"]=> int(0) ["failed"]=> int(0) }`. The loop has nothing to do but completes cleanly.

- [ ] **Step 5: Smoke-test the loop with one enrollment**

```bash
php artisan tinker --execute="\App\Services\SettingService::setGrowthCirclesTaxId(999); \$n = \App\Models\Nation::query()->where('num_cities', '>', 0)->first(); \$a = \App\Models\Account::query()->where('nation_id', \$n->id)->first(); app(\App\Services\GrowthCircleService::class)->enroll(\$n, \$a); dump(app(\App\Services\GrowthCircleService::class)->runDailyDistribution()); dump(\App\Models\GrowthCircleDistribution::query()->where('nation_id', \$n->id)->count());"
```
Expected: counts dump showing one of `distributed`, `skipped`, or `failed` set to 1 (the others 0). If the test nation has no profitability snapshot you will see `skipped: 1`. The `GrowthCircleDistribution::count()` is `1` if and only if `distributed: 1`.

Re-run the same command. The second invocation must show `skipped: 1` (the unique constraint catches the same-day duplicate) and the count remains `1`.

Cleanup:
```bash
php artisan tinker --execute="app(\App\Services\GrowthCircleService::class)->disenroll(\App\Models\Nation::query()->where('num_cities', '>', 0)->first()); \App\Models\GrowthCircleDistribution::query()->delete(); \App\Services\SettingService::setGrowthCirclesTaxId(0);"
```

- [ ] **Step 6: Format and commit**

```bash
./vendor/bin/pint app/Services/GrowthCircleService.php
git add app/Services/GrowthCircleService.php
git commit -m "Add runDailyDistribution and distributeOne to GrowthCircleService"
```

### Task 2.7: Symmetric DD modification — auto-switch from Growth Circles

**Files:**
- Modify: `app/Services/DirectDepositService.php`

Add the symmetric ~5-line check at the top of `DirectDepositService::enroll()` so enrolling in DD while a Growth Circles enrollment exists captures the original `previous_tax_id` and disenrolls from GC first.

- [ ] **Step 1: Locate `enroll()` (currently at `app/Services/DirectDepositService.php:210`)**

The current method body starts:

```php
    public function enroll(Nation $nation, Account $account): void
    {
        $ddTaxId = $this->ddTaxId;
        $currentTaxId = $nation->tax_id;

        // Determine previous tax ID
        $previousTaxId = ($currentTaxId === $ddTaxId)
            ? SettingService::getDirectDepositFallbackId()
            : $currentTaxId;
```

- [ ] **Step 2: Replace the `Determine previous tax ID` block with auto-switch logic**

Replace:

```php
        // Determine previous tax ID
        $previousTaxId = ($currentTaxId === $ddTaxId)
            ? SettingService::getDirectDepositFallbackId()
            : $currentTaxId;
```

with:

```php
        // Determine previous tax ID. If a Growth Circles enrollment exists,
        // capture its previous_tax_id (the *original* pre-program bracket)
        // and disenroll from Growth Circles before proceeding. The capture
        // happens FIRST so the disenroll side effect cannot lose the value.
        if ($gcEnrollment = \App\Models\GrowthCircleEnrollment::query()->where('nation_id', $nation->id)->first()) {
            $previousTaxId = (int) $gcEnrollment->previous_tax_id;          // capture FIRST
            app(GrowthCircleService::class)->disenroll($nation);            // then mutate
        } else {
            $previousTaxId = ($currentTaxId === $ddTaxId)
                ? SettingService::getDirectDepositFallbackId()
                : $currentTaxId;
        }
```

If the file imports `App\Models\GrowthCircleEnrollment` already, drop the FQCN. Otherwise either add the `use` at the top of the file or leave the FQCN inline (Pint may rewrite it; either is acceptable).

- [ ] **Step 3: Smoke-test the round-trip across both programs**

This verifies the database `previous_tax_id` chain across switches. Note: `TaxBracketService::send()` dispatches an async job, so the live `nations.tax_id` column does not change during this tinker invocation. The assertion is therefore about what `previous_tax_id` each enrollment row stores — which is exactly what determines correct restoration when the user later disenrolls. We're verifying that switching DD → GC → DD always carries the *original* `previous_tax_id` forward, never the other program's tax_id.

```bash
php artisan tinker --execute="\App\Services\SettingService::setDirectDepositId(50); \App\Services\SettingService::setGrowthCirclesTaxId(60); \$n = \App\Models\Nation::query()->where('num_cities', '>', 0)->first(); \$a = \App\Models\Account::query()->where('nation_id', \$n->id)->first(); \$originalTaxId = (int) \$n->tax_id; dump('original', \$originalTaxId); app(\App\Services\DirectDepositService::class)->enroll(\$n, \$a); dump('after dd', \App\Models\DirectDepositEnrollment::query()->where('nation_id', \$n->id)->value('previous_tax_id')); app(\App\Services\GrowthCircleService::class)->enroll(\$n, \$a); dump('after gc', \App\Models\GrowthCircleEnrollment::query()->where('nation_id', \$n->id)->value('previous_tax_id'), 'dd row gone?', !\App\Models\DirectDepositEnrollment::query()->where('nation_id', \$n->id)->exists()); app(\App\Services\DirectDepositService::class)->enroll(\$n, \$a); dump('back to dd', \App\Models\DirectDepositEnrollment::query()->where('nation_id', \$n->id)->value('previous_tax_id'), 'gc row gone?', !\App\Models\GrowthCircleEnrollment::query()->where('nation_id', \$n->id)->exists()); app(\App\Services\DirectDepositService::class)->disenroll(\$n); \App\Services\SettingService::setDirectDepositId(0); \App\Services\SettingService::setGrowthCirclesTaxId(0);"
```

Expected:
- `original` matches the nation's actual current tax_id (not 50 or 60).
- `after dd` equals the original tax_id.
- `after gc` equals the *same* original tax_id (NOT 50, the DD bracket). `dd row gone?` is `true`.
- `back to dd` equals the original tax_id again. `gc row gone?` is `true`.

If `after gc` reports the DD bracket (50) instead of the original, the capture-then-mutate ordering is broken — re-read Step 2.

- [ ] **Step 4: Format and commit**

```bash
./vendor/bin/pint app/Services/DirectDepositService.php
git add app/Services/DirectDepositService.php
git commit -m "Auto-switch from Growth Circles when enrolling in DirectDeposit"
```

### Task 2.8: Sanity check the chunk

- [ ] **Step 1: Confirm no committed `tinker` cleanup leftovers**

```bash
php artisan tinker --execute="dump(\App\Models\GrowthCircleEnrollment::query()->count(), \App\Models\GrowthCircleDistribution::query()->count(), \App\Models\DirectDepositEnrollment::query()->count(), \App\Services\SettingService::getGrowthCirclesTaxId(), \App\Services\SettingService::getDirectDepositId());"
```
Expected: counts and tax-ids that match what the dev environment had *before* this chunk's smoke tests. If anything is non-zero unexpectedly, run the cleanup commands inline above to clear them.

- [ ] **Step 2: Confirm clean working tree**

```bash
git status
```
Expected: `nothing to commit, working tree clean`.

End of Chunk 2.

---

## Chunk 3: Console Command and Schedule

This chunk wires up the daily distribution job. After it lands, `php artisan growth-circles:distribute` runs the cycle, and the scheduler invokes it daily at 03:00 UTC, gated on `PWHealthService::isUp()`.

### Task 3.1: Create `DistributeGrowthCirclesCommand`

**Files:**
- Create: `app/Console/Commands/DistributeGrowthCirclesCommand.php`

Thin wrapper around `GrowthCircleService::runDailyDistribution()` that prints a summary line and structured-logs the counts. Mirrors `RunDailyPayroll`'s shape (`app/Console/Commands/RunDailyPayroll.php`).

- [ ] **Step 1: Generate the command file**

```bash
php artisan make:command DistributeGrowthCirclesCommand
```
Expected: file appears at `app/Console/Commands/DistributeGrowthCirclesCommand.php`.

- [ ] **Step 2: Replace generated content with the implementation**

```php
<?php

namespace App\Console\Commands;

use App\Services\GrowthCircleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DistributeGrowthCirclesCommand extends Command
{
    protected $signature = 'growth-circles:distribute {--cycle-date= : Override the cycle date (YYYY-MM-DD UTC). Defaults to today UTC.}';

    protected $description = 'Run the daily Growth Circles distribution: credit each enrolled member their food and uranium shortfall.';

    public function handle(GrowthCircleService $service): int
    {
        $cycleDate = $this->option('cycle-date') ?: Carbon::now('UTC')->toDateString();
        $counts = $service->runDailyDistribution($cycleDate);

        $message = sprintf(
            'Growth Circles distribution complete for %s: %d distributed, %d skipped, %d failed.',
            $cycleDate,
            $counts['distributed'],
            $counts['skipped'],
            $counts['failed'],
        );
        $this->info($message);

        Log::info('Growth Circles distribution completed', [
            'cycle_date' => $cycleDate,
            ...$counts,
        ]);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 3: Verify the command is registered**

```bash
php artisan list | grep growth-circles
```
Expected: a single line:
```
  growth-circles:distribute  Run the daily Growth Circles distribution: credit each enrolled member their food and uranium shortfall.
```

- [ ] **Step 4: Dry-run with no enrollments**

```bash
php artisan growth-circles:distribute
```
Expected output:
```
Growth Circles distribution complete for <today>: 0 distributed, 0 skipped, 0 failed.
```

- [ ] **Step 5: Format and commit**

```bash
./vendor/bin/pint app/Console/Commands/DistributeGrowthCirclesCommand.php
git add app/Console/Commands/DistributeGrowthCirclesCommand.php
git commit -m "Add DistributeGrowthCirclesCommand"
```

### Task 3.2: Add the daily schedule entry to `routes/console.php`

**Files:**
- Modify: `routes/console.php`

The existing file already exposes `$whenPWUp` (a fn closure) for gating commands on `PWHealthService::isUp()`. Use that gate; pick 03:00 UTC per the spec.

- [ ] **Step 1: Add the schedule entry**

Locate the section in `routes/console.php` containing other domain-specific schedules (loans, payroll, etc.). After the payroll block (around the `Schedule::command('payroll:run-daily')` block), add:

```php
// Growth Circles
Schedule::command('growth-circles:distribute')
    ->dailyAt('03:00')
    ->timezone('UTC')
    ->withoutOverlapping(120)
    ->when($whenPWUp);
```

Spec note: §7.2 uses `->skip(fn () => ! app(PWHealthService::class)->isUp())`. The `->when($whenPWUp)` form is semantically equivalent and is the convention already established at the top of `routes/console.php` (line 11). The explicit `->timezone('UTC')` defends against server-tz drift; the spec is silent on it but the wall clock must be UTC for `cycle_date` consistency with `runDailyDistribution`.

- [ ] **Step 2: Verify the schedule registers**

```bash
php artisan schedule:list | grep growth-circles
```
Expected: a single line showing the command, the cron expression for `0 3 * * *`, and a "next due" timestamp.

If `schedule:list` is unavailable in this Laravel version, run:
```bash
php artisan schedule:test --name="growth-circles:distribute"
```
Expected: the command executes once, prints the same summary line as the manual run.

- [ ] **Step 3: Format and commit**

```bash
./vendor/bin/pint routes/console.php
git add routes/console.php
git commit -m "Schedule growth-circles:distribute daily at 03:00 UTC"
```

### Task 3.3: Sanity check the chunk

- [ ] **Step 1: Confirm the command + schedule are both wired and firing at the right time**

```bash
php artisan list 2>&1 | grep growth-circles
php artisan schedule:list 2>&1 | grep growth-circles
```
Expected: `php artisan list` prints exactly one matching line; `schedule:list` prints exactly one line containing the cron expression `0 3 * * *` and the timezone `UTC`.

- [ ] **Step 2: Confirm clean working tree**

```bash
git status
```
Expected: `nothing to commit, working tree clean`.

End of Chunk 3.

---

## Chunk 4: User-facing HTTP and UI

This chunk lets members enroll, switch, and disenroll themselves. The new card slots into the existing `resources/views/accounts/index.blade.php` page alongside the DirectDeposit card (the convention for member-managed programs in this codebase). After this chunk lands, members can self-serve from the browser; the daily distribution scheduled in Chunk 3 will pick up newly enrolled rows on its next run.

### Task 4.1: Create `EnrollGrowthCirclesRequest` form request

**Files:**
- Create: `app/Http/Requests/EnrollGrowthCirclesRequest.php`

Per `CLAUDE.md`, new HTTP code uses Form Requests (existing `DirectDepositController` uses inline `$request->validate()` but is not the standard for new code).

- [ ] **Step 1: Generate the form request**

```bash
php artisan make:request EnrollGrowthCirclesRequest
```

- [ ] **Step 2: Replace generated content**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class EnrollGrowthCirclesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check() && Auth::user()->nation_id !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $nationId = (int) Auth::user()->nation_id;

        return [
            'account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where('nation_id', $nationId),
            ],
        ];
    }
}
```

- [ ] **Step 3: Verify the class loads**

```bash
php artisan tinker --execute="dump(class_exists(\App\Http\Requests\EnrollGrowthCirclesRequest::class));"
```
Expected: `bool(true)`.

- [ ] **Step 4: Format and commit**

```bash
./vendor/bin/pint app/Http/Requests/EnrollGrowthCirclesRequest.php
git add app/Http/Requests/EnrollGrowthCirclesRequest.php
git commit -m "Add EnrollGrowthCirclesRequest form request"
```

### Task 4.2: Create the user-facing `GrowthCirclesController`

**Files:**
- Create: `app/Http/Controllers/GrowthCirclesController.php`

- [ ] **Step 1: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Exceptions\UserErrorException;
use App\Http\Requests\EnrollGrowthCirclesRequest;
use App\Models\Account;
use App\Services\GrowthCircleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class GrowthCirclesController extends Controller
{
    public function __construct(
        protected GrowthCircleService $growthCircles,
    ) {}

    public function enroll(EnrollGrowthCirclesRequest $request): RedirectResponse
    {
        $nation = Auth::user()->nation;
        $account = Account::findOrFail($request->validated()['account_id']);

        try {
            $this->growthCircles->enroll($nation, $account);
        } catch (UserErrorException $e) {
            return back()->with([
                'alert-message' => $e->getMessage(),
                'alert-type' => 'error',
            ]);
        }

        return back()->with([
            'alert-message' => 'You have been enrolled in Growth Circles.',
            'alert-type' => 'success',
        ]);
    }

    public function disenroll(): RedirectResponse
    {
        $nation = Auth::user()->nation;

        $this->growthCircles->disenroll($nation);

        return back()->with([
            'alert-message' => 'You have been disenrolled from Growth Circles.',
            'alert-type' => 'success',
        ]);
    }
}
```

- [ ] **Step 2: Verify the class loads**

```bash
php artisan tinker --execute="dump(class_exists(\App\Http\Controllers\GrowthCirclesController::class));"
```
Expected: `bool(true)`.

- [ ] **Step 3: Format and commit**

```bash
./vendor/bin/pint app/Http/Controllers/GrowthCirclesController.php
git add app/Http/Controllers/GrowthCirclesController.php
git commit -m "Add GrowthCirclesController with enroll and disenroll actions"
```

### Task 4.3: Wire user routes in `routes/web.php`

**Files:**
- Modify: `routes/web.php`

Add the two routes inside the same authenticated middleware group as the DD routes (around `routes/web.php:159-163`).

- [ ] **Step 1: Add the import near the existing controller imports**

Near `use App\Http\Controllers\DirectDepositController;` (line 45), add:

```php
use App\Http\Controllers\GrowthCirclesController;
```

- [ ] **Step 2: Add the routes immediately after the DD routes**

After the existing block that ends with `Route::post('/direct-deposit/disenroll', [DirectDepositController::class, 'disenroll'])->name('dd.disenroll')->middleware(BlockWhenPWDown::class);`, add:

```php
    // Growth Circles
    Route::post('/growth-circles/enroll', [GrowthCirclesController::class, 'enroll'])
        ->name('growth-circles.enroll')
        ->middleware(BlockWhenPWDown::class);
    Route::post('/growth-circles/disenroll', [GrowthCirclesController::class, 'disenroll'])
        ->name('growth-circles.disenroll')
        ->middleware(BlockWhenPWDown::class);
```

- [ ] **Step 3: Verify routes resolve**

```bash
php artisan route:list --columns=method,uri,name | grep growth-circles
```
Expected: two lines, one each for `growth-circles.enroll` and `growth-circles.disenroll`, both POST.

- [ ] **Step 4: Format and commit**

```bash
./vendor/bin/pint routes/web.php
git add routes/web.php
git commit -m "Add user routes for Growth Circles enroll/disenroll"
```

### Task 4.4: Create the user-facing card partial

**Files:**
- Create: `resources/views/accounts/components/growth_circles.blade.php`

Mirrors the structure of `resources/views/accounts/components/direct_deposit.blade.php`. Three states: not-enrolled (with eligibility line), enrolled-active, enrolled-paused (eligibility currently failing).

The view receives these variables from `AccountsController` (set up in Task 4.5):
- `$accounts` (collection of the user's `Account`s)
- `$gcEnrollment` (the user's `GrowthCircleEnrollment` or null)
- `$gcEligibility` (the result of `evaluateEligibility($nation)` — `['eligible' => bool, 'reason' => ?string]`)
- `$gcRecentDistributions` (last 7 `GrowthCircleDistribution` rows for the nation, may be empty)
- `$ddEnrolled` (bool — whether the user is currently enrolled in DirectDeposit, used to swap the button label/copy)

- [ ] **Step 1: Write the Blade partial**

```blade
@php
    $isEnrolled = $gcEnrollment !== null;
    $isPaused = $isEnrolled && ! ($gcEligibility['eligible'] ?? true);
    $isEligible = (bool) ($gcEligibility['eligible'] ?? false);
    $lastDistribution = $gcRecentDistributions->first();
@endphp

<x-utils.card title="Growth Circles" extraClasses="space-y-3">
    @if ($isEnrolled && ! $isPaused)
        {{-- Active enrollment (§9.2) --}}
        <div class="rounded-xl bg-success/10 border border-success/30 p-4">
            <p class="text-success font-semibold">
                Enrolled — depositing into
                <span class="font-bold">{{ $gcEnrollment->account?->name ?? '(deleted account)' }}</span>.
            </p>
        </div>

        @if ($lastDistribution)
            <p class="text-sm">
                <span class="font-semibold">Last distribution:</span>
                {{ $lastDistribution->cycle_date->toDateString() }} —
                {{ number_format($lastDistribution->food, 2) }} food,
                {{ number_format($lastDistribution->uranium, 2) }} uranium
            </p>
        @else
            <p class="text-sm text-base-content/60">No distributions yet.</p>
        @endif

        @if ($gcRecentDistributions->isNotEmpty())
            <details class="rounded-lg border border-base-300 p-3">
                <summary class="cursor-pointer text-sm font-medium">Recent distributions (last 7 cycles)</summary>
                <table class="table table-xs mt-2 w-full">
                    <thead>
                    <tr>
                        <th>Cycle</th>
                        <th class="text-right">Food</th>
                        <th class="text-right">Uranium</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($gcRecentDistributions as $row)
                        <tr>
                            <td>{{ $row->cycle_date->toDateString() }}</td>
                            <td class="text-right">{{ number_format($row->food, 2) }}</td>
                            <td class="text-right">{{ number_format($row->uranium, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </details>
        @endif

        <p class="text-xs text-base-content/60">Next distribution: tomorrow ~03:00 UTC.</p>

        <form method="POST" action="{{ route('growth-circles.disenroll') }}">
            @csrf
            <button class="btn btn-error w-full" type="submit">Disenroll from Growth Circles</button>
        </form>

    @elseif ($isPaused)
        {{-- Enrolled but paused — §9.2.1 (eligibility gate failing) --}}
        <div class="rounded-xl bg-warning/10 border border-warning/40 p-4">
            <p class="mb-1 text-warning font-semibold">Paused — {{ $gcEligibility['reason'] }}</p>
            <p class="text-sm text-base-content/80">
                Distributions will resume automatically when this condition clears.
                You are still enrolled and depositing into
                <span class="font-bold">{{ $gcEnrollment->account?->name ?? '(deleted account)' }}</span>.
            </p>
        </div>

        @if ($lastDistribution)
            <p class="text-sm">
                <span class="font-semibold">Last distribution:</span>
                {{ $lastDistribution->cycle_date->toDateString() }} —
                {{ number_format($lastDistribution->food, 2) }} food,
                {{ number_format($lastDistribution->uranium, 2) }} uranium
            </p>
        @endif

        @if ($gcRecentDistributions->isNotEmpty())
            <details class="rounded-lg border border-base-300 p-3">
                <summary class="cursor-pointer text-sm font-medium">Recent distributions (last 7 cycles)</summary>
                <table class="table table-xs mt-2 w-full">
                    <thead>
                    <tr>
                        <th>Cycle</th>
                        <th class="text-right">Food</th>
                        <th class="text-right">Uranium</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($gcRecentDistributions as $row)
                        <tr>
                            <td>{{ $row->cycle_date->toDateString() }}</td>
                            <td class="text-right">{{ number_format($row->food, 2) }}</td>
                            <td class="text-right">{{ number_format($row->uranium, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </details>
        @endif

        <form method="POST" action="{{ route('growth-circles.disenroll') }}">
            @csrf
            <button class="btn btn-outline btn-error w-full" type="submit">Disenroll from Growth Circles</button>
        </form>

    @else
        {{-- Not enrolled — §9.1 / §9.3 --}}
        <div class="rounded-xl bg-info/10 border border-info/30 p-4">
            <p class="mb-1 text-info font-semibold">Growth Circles</p>
            <p class="text-sm text-base-content/80">
                Contribute 100% of your tax income. In return, the alliance ships your daily food and uranium consumption to your selected account.
            </p>
        </div>

        @if ($isEligible)
            <p class="text-success text-sm">Eligible to enroll.</p>
        @else
            <div class="rounded-xl bg-error/10 border border-error/30 p-3">
                <p class="text-error text-sm">Not currently eligible: {{ $gcEligibility['reason'] }}</p>
            </div>
        @endif

        <form method="POST" action="{{ route('growth-circles.enroll') }}" class="space-y-3"
              @if (! $isEligible) onsubmit="return false;" @endif>
            @csrf
            <label class="label" for="gc_account_id">
                <span class="label-text">Choose an account for distributions:</span>
            </label>
            <select name="account_id" id="gc_account_id" class="select select-bordered w-full" required
                    @if (! $isEligible) disabled @endif>
                @foreach($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
            </select>

            @if ($ddEnrolled)
                <p class="text-xs text-base-content/70">
                    You are currently enrolled in DirectDeposit. Enrolling here will switch you over; your original pre-program tax bracket is preserved for restoration.
                </p>
                <button class="btn btn-primary w-full" type="submit"
                        @if (! $isEligible) disabled @endif
                        onclick="return confirm('This will disenroll you from DirectDeposit and enroll you in Growth Circles. Your tax bracket will change to the 100% Growth Circles bracket. Continue?');">
                    Switch from DirectDeposit
                </button>
            @else
                <button class="btn btn-primary w-full" type="submit"
                        @if (! $isEligible) disabled @endif>
                    Enroll in Growth Circles
                </button>
            @endif
        </form>
    @endif
</x-utils.card>
```

The confirm-dialog wording in the "Switch from DirectDeposit" branch is taken verbatim from spec §9.3.

- [ ] **Step 2: No browser test yet** — view rendering depends on the controller wiring in Task 4.5.

- [ ] **Step 3: Format and commit**

```bash
./vendor/bin/pint resources/views/accounts/components/growth_circles.blade.php
git add resources/views/accounts/components/growth_circles.blade.php
git commit -m "Add Growth Circles card partial"
```

### Task 4.5: Surface Growth Circles data from `AccountsController`

**Files:**
- Modify: `app/Http/Controllers/AccountsController.php`

The method that returns view data lives near `app/Http/Controllers/AccountsController.php:422-436` (the `return [ ... ]` block in the index/dashboard accessor). Add the four new keys.

- [ ] **Step 1: Add imports**

Near the top of the file, add:

```php
use App\Models\GrowthCircleDistribution;
use App\Models\GrowthCircleEnrollment;
use App\Services\GrowthCircleService;
```

- [ ] **Step 2: Hoist the DD enrollment lookup into a local and add the GC variables**

Before editing, grep for any other `DirectDepositEnrollment::` references in this method to consolidate them:

```bash
grep -n "DirectDepositEnrollment" app/Http/Controllers/AccountsController.php
```

The current `return [...]` block constructs the DD enrollment inline (around line 425):
```php
'enrollment' => DirectDepositEnrollment::with('account')->where('nation_id', $nationId)->first(),
```
Replace that with two lines computed *before* the return block. If the grep found additional inline `DirectDepositEnrollment` references in the same method, replace them with `$ddEnrollment` too.

```php
        $ddEnrollment = DirectDepositEnrollment::with('account')->where('nation_id', $nationId)->first();
        $ddEnrolled = $ddEnrollment !== null;

        $gcEnrollment = GrowthCircleEnrollment::with('account')
            ->where('nation_id', $nationId)
            ->first();

        $gcEligibility = app(GrowthCircleService::class)
            ->evaluateEligibility(Auth::user()->nation);

        $gcRecentDistributions = GrowthCircleDistribution::query()
            ->where('nation_id', $nationId)
            ->orderByDesc('cycle_date')
            ->limit(7)
            ->get();
```

This preserves the DD enrollment availability for the existing `enrollment` view variable while also exposing `$ddEnrolled` for the GC card's "Switch from DirectDeposit" branch.

- [ ] **Step 3: Update the return array**

Replace the inline `'enrollment' => DirectDepositEnrollment::with(...)...` line with `'enrollment' => $ddEnrollment,` and append the four new keys:

```php
        return [
            // ... preserve existing keys (accounts, activeLoans, etc.)
            'enrollment' => $ddEnrollment,        // was inline; now uses local
            // ... preserve other existing keys (bracket, mmrConfig, etc.)
            'gcEnrollment' => $gcEnrollment,
            'gcEligibility' => $gcEligibility,
            'gcRecentDistributions' => $gcRecentDistributions,
            'ddEnrolled' => $ddEnrolled,
        ];
```

- [ ] **Step 4: Verify nothing crashed**

```bash
php artisan config:clear
```
Then visit the accounts page in a browser (the named route `accounts.index` — typically `/accounts`) logged in as a member nation. The page should render without exceptions; the new card area is empty until Task 4.6 includes it.

If a 500 error appears, check `storage/logs/laravel.log` for missing-import / undefined-variable issues.

- [ ] **Step 5: Format and commit**

```bash
./vendor/bin/pint app/Http/Controllers/AccountsController.php
git add app/Http/Controllers/AccountsController.php
git commit -m "Provide Growth Circles view data from AccountsController"
```

### Task 4.6: Include the card in the accounts page

**Files:**
- Modify: `resources/views/accounts/index.blade.php`

The DD card include lives at line 34 (`@include("accounts.components.direct_deposit")`). Add the GC include immediately after it.

- [ ] **Step 1: Add the include**

Find:
```blade
            @include("accounts.components.direct_deposit")
```

After it, add:
```blade
            @include("accounts.components.growth_circles")
```

- [ ] **Step 2: Browser smoke test — not enrolled**

Pre-req: a configured Growth Circles tax_id and fallback. Use real bracket IDs from your dev environment — list them first if you don't know:

```bash
php artisan tinker --execute="dump(\DB::table('tax_brackets')->select('id','bracket_name')->orderBy('id')->get()->toArray());"
```

Set the IDs (substitute real values for `<gcId>` and `<fallbackId>`):

```bash
php artisan tinker --execute="\App\Services\SettingService::setGrowthCirclesTaxId(<gcId>); \App\Services\SettingService::setGrowthCirclesFallbackTaxId(<fallbackId>);"
```

Visit the accounts page in the browser. Expected: the Growth Circles card renders with the "Not enrolled" panel, the explainer text, an account dropdown, and an "Enroll in Growth Circles" button. If the user is currently DD-enrolled, the button reads "Switch from DirectDeposit" instead and the explainer paragraph mentions the switch.

- [ ] **Step 3: Browser smoke test — enroll**

Click "Enroll in Growth Circles" with a valid account selected. Expected:
- Page reloads.
- A success flash appears: "You have been enrolled in Growth Circles."
- The card now shows the "Enrolled" panel with the account name and a "Disenroll" button.
- A `growth_circle_enrollments` row exists for the user's nation_id.

Verify the row:
```bash
php artisan tinker --execute="\$nationId = \App\Models\Nation::query()->where('num_cities', '>', 0)->value('id'); dump(\App\Models\GrowthCircleEnrollment::where('nation_id', \$nationId)->first()?->toArray());"
```

- [ ] **Step 4: Browser smoke test — distribute and view recent**

Trigger a distribution to populate the recent-distributions table:
```bash
php artisan growth-circles:distribute
```

Reload the accounts page. Expected:
- The "Recent distributions" details element appears.
- It contains one row with today's date, the food shortfall, and the uranium shortfall (each may be `0.00` if the test nation is a net producer or has no shortfall).

If both numbers are `0.00`, expand the recent distributions table by clicking the summary; the row should still be present (the loop in Chunk 2 logs even zero-shortfall outcomes as `skipped`, in which case no row will appear — that's correct behavior, no entry means no shortfall this cycle).

- [ ] **Step 5: Browser smoke test — disenroll**

Click "Disenroll from Growth Circles". Expected:
- Success flash: "You have been disenrolled from Growth Circles."
- Card returns to the "Not enrolled" state.
- `growth_circle_enrollments` row is gone.

Cleanup:
```bash
php artisan tinker --execute="\App\Services\SettingService::setGrowthCirclesTaxId(0); \App\Services\SettingService::setGrowthCirclesFallbackTaxId(0); \App\Models\GrowthCircleDistribution::query()->delete();"
```

- [ ] **Step 6: Format and commit**

```bash
./vendor/bin/pint resources/views/accounts/index.blade.php
git add resources/views/accounts/index.blade.php
git commit -m "Surface Growth Circles card on the accounts page"
```

### Task 4.7: Sanity check the chunk

- [ ] **Step 1: Confirm clean working tree**

```bash
git status
```
Expected: `nothing to commit, working tree clean`.

- [ ] **Step 2: Confirm the routes still resolve**

```bash
php artisan route:list 2>&1 | grep growth-circles
```
Expected: two POST routes — `growth-circles.enroll` and `growth-circles.disenroll`.

- [ ] **Step 3: Confirm the dashboard still renders for both DD-enrolled and GC-enrolled members**

Smoke-check by visiting the accounts page logged in as a member nation. The DD and GC cards should render side by side; the labels and buttons should reflect the current enrollment state correctly for whichever program is active.

End of Chunk 4.

---

## Chunk 5: Admin-facing HTTP and UI

This chunk delivers the admin surface for Growth Circles per spec §10: a settings + enrollments page (`/admin/growth-circles`) and a distribution history page (`/admin/growth-circles/history`). Per-row admin actions (force-disenroll, reapply tax bracket) are wired in the same controller. The index page co-hosts the settings form *and* the enrollments table, mirroring the DD admin pattern (`resources/views/admin/accounts/direct_deposit.blade.php`) which keeps tax-id settings + brackets table in one view.

> Convention note: although `CLAUDE.md` describes admin pages as Bootstrap+AdminLTE, the live admin pages in this repo (e.g. `admin/accounts/direct_deposit.blade.php`, `admin/defense/rebuilding.blade.php`) actually use Tailwind+DaisyUI components (`<x-card>`, `<x-button>`, `btn btn-primary`, etc.). The plan follows what the code does rather than what the docs say.

### Task 5.1: Create the admin controller

**Files:**
- Create: `app/Http/Controllers/Admin/GrowthCirclesController.php`

- [ ] **Step 1: Write the controller**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GrowthCircleDistribution;
use App\Models\GrowthCircleEnrollment;
use App\Models\Nation;
use App\Services\AuditLogger;
use App\Services\GrowthCircleService;
use App\Services\SettingService;
use App\Services\TaxBracketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GrowthCirclesController extends Controller
{
    public function __construct(
        protected GrowthCircleService $growthCircles,
        protected AuditLogger $auditLogger,
    ) {}

    public function index(): View
    {
        $this->authorize('view-growth-circles');

        $enrollments = GrowthCircleEnrollment::query()
            ->with(['nation', 'account'])
            ->orderBy('enrolled_at')
            ->get()
            ->map(function (GrowthCircleEnrollment $enrollment): array {
                $eligibility = $enrollment->nation
                    ? $this->growthCircles->evaluateEligibility($enrollment->nation)
                    : ['eligible' => false, 'reason' => 'Nation no longer exists.'];

                $last = GrowthCircleDistribution::query()
                    ->where('nation_id', $enrollment->nation_id)
                    ->orderByDesc('cycle_date')
                    ->first();

                $sevenDayTotal = GrowthCircleDistribution::query()
                    ->where('nation_id', $enrollment->nation_id)
                    ->where('cycle_date', '>=', now()->subDays(7)->toDateString())
                    ->selectRaw('COALESCE(SUM(food), 0) as food, COALESCE(SUM(uranium), 0) as uranium')
                    ->first();

                return [
                    'enrollment' => $enrollment,
                    'eligibility' => $eligibility,
                    'last' => $last,
                    'seven_day_food' => (float) ($sevenDayTotal->food ?? 0),
                    'seven_day_uranium' => (float) ($sevenDayTotal->uranium ?? 0),
                ];
            });

        return view('admin.growth-circles.index', [
            'taxId' => SettingService::getGrowthCirclesTaxId(),
            'fallbackTaxId' => SettingService::getGrowthCirclesFallbackTaxId(),
            'rows' => $enrollments,
        ]);
    }

    public function history(Request $request): View
    {
        $this->authorize('view-growth-circles');

        $query = GrowthCircleDistribution::query()
            ->with(['nation:id,nation_name', 'account:id,name'])
            ->orderByDesc('cycle_date')
            ->orderByDesc('id');

        if ($from = $request->query('from')) {
            $query->where('cycle_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('cycle_date', '<=', $to);
        }
        if ($nationId = $request->query('nation_id')) {
            $query->where('nation_id', (int) $nationId);
        }
        if ($accountId = $request->query('account_id')) {
            $query->where('account_id', (int) $accountId);
        }

        return view('admin.growth-circles.history', [
            'rows' => $query->paginate(50)->withQueryString(),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $this->authorize('manage-growth-circles');

        $validated = $request->validate([
            'growth_circles_tax_id' => 'required|integer|min:1',
            'growth_circles_fallback_tax_id' => 'required|integer|min:1',
        ]);

        $previous = [
            'growth_circles_tax_id' => SettingService::getGrowthCirclesTaxId(),
            'growth_circles_fallback_tax_id' => SettingService::getGrowthCirclesFallbackTaxId(),
        ];

        SettingService::setGrowthCirclesTaxId((int) $validated['growth_circles_tax_id']);
        SettingService::setGrowthCirclesFallbackTaxId((int) $validated['growth_circles_fallback_tax_id']);

        $this->auditLogger->success(
            category: 'growth_circles',
            action: 'settings_updated',
            context: [
                'changes' => [
                    'growth_circles_tax_id' => [
                        'from' => $previous['growth_circles_tax_id'],
                        'to' => (int) $validated['growth_circles_tax_id'],
                    ],
                    'growth_circles_fallback_tax_id' => [
                        'from' => $previous['growth_circles_fallback_tax_id'],
                        'to' => (int) $validated['growth_circles_fallback_tax_id'],
                    ],
                ],
            ],
        );

        return back()->with([
            'alert-message' => 'Growth Circles settings saved.',
            'alert-type' => 'success',
        ]);
    }

    public function forceDisenroll(Nation $nation): RedirectResponse
    {
        $this->authorize('manage-growth-circles');

        $this->growthCircles->disenroll($nation);

        $this->auditLogger->success(
            category: 'growth_circles',
            action: 'admin_disenrolled',
            subject: $nation,
            context: ['data' => ['nation_id' => $nation->id]],
            message: "Admin force-disenrolled nation {$nation->nation_name} from Growth Circles.",
        );

        return back()->with([
            'alert-message' => "Force-disenrolled {$nation->nation_name} from Growth Circles.",
            'alert-type' => 'success',
        ]);
    }

    public function reapplyBracket(Nation $nation): RedirectResponse
    {
        $this->authorize('manage-growth-circles');
        $this->authorize('view-diagnostic-info');

        $taxId = SettingService::getGrowthCirclesTaxId();
        if ($taxId <= 0) {
            return back()->with([
                'alert-message' => 'Growth Circles tax bracket is not configured.',
                'alert-type' => 'error',
            ]);
        }

        $mutation = new TaxBracketService;
        $mutation->id = $taxId;
        $mutation->target_id = (int) $nation->id;
        $mutation->send();

        $this->auditLogger->success(
            category: 'growth_circles',
            action: 'bracket_reapplied',
            subject: $nation,
            context: ['data' => ['nation_id' => $nation->id, 'tax_id' => $taxId]],
            message: "Re-applied Growth Circles tax bracket for nation {$nation->nation_name}.",
        );

        return back()->with([
            'alert-message' => "Re-applied Growth Circles tax bracket for {$nation->nation_name}.",
            'alert-type' => 'success',
        ]);
    }
}
```

- [ ] **Step 2: Verify the class loads**

```bash
php artisan tinker --execute="dump(class_exists(\App\Http\Controllers\Admin\GrowthCirclesController::class));"
```
Expected: `bool(true)`.

- [ ] **Step 3: Format and commit**

```bash
./vendor/bin/pint app/Http/Controllers/Admin/GrowthCirclesController.php
git add app/Http/Controllers/Admin/GrowthCirclesController.php
git commit -m "Add admin Growth Circles controller"
```

### Task 5.2: Wire admin routes

**Files:**
- Modify: `routes/web.php`

The admin route group starts at `routes/web.php:222` (the block guarded by `AdminMiddleware`). Add the new routes inside that group near the existing DD admin routes (around line 326).

- [ ] **Step 1: Add the controller import**

Near the existing admin controller imports (top of file, around line 27-30), add:

```php
use App\Http\Controllers\Admin\GrowthCirclesController as AdminGrowthCirclesController;
```

- [ ] **Step 2: Add the route block inside the admin middleware group**

Inside the admin route group, near the DD admin routes block (around line 326-336), add:

```php
        // Growth Circles
        Route::get('/admin/growth-circles', [AdminGrowthCirclesController::class, 'index'])
            ->name('admin.growth-circles.index');

        Route::get('/admin/growth-circles/history', [AdminGrowthCirclesController::class, 'history'])
            ->name('admin.growth-circles.history');

        Route::post('/admin/growth-circles/settings', [AdminGrowthCirclesController::class, 'saveSettings'])
            ->name('admin.growth-circles.settings');

        Route::post('/admin/growth-circles/enrollments/{nation}/disenroll', [AdminGrowthCirclesController::class, 'forceDisenroll'])
            ->name('admin.growth-circles.force-disenroll')
            ->middleware(BlockWhenPWDown::class);

        Route::post('/admin/growth-circles/enrollments/{nation}/reapply-bracket', [AdminGrowthCirclesController::class, 'reapplyBracket'])
            ->name('admin.growth-circles.reapply-bracket')
            ->middleware(BlockWhenPWDown::class);
```

- [ ] **Step 3: Verify routes resolve**

```bash
php artisan route:list --columns=method,uri,name | grep growth-circles
```
Expected: 7 GC routes — 2 user routes (`growth-circles.enroll`, `growth-circles.disenroll`) plus 5 admin routes (`admin.growth-circles.index`, `admin.growth-circles.history`, `admin.growth-circles.settings`, `admin.growth-circles.force-disenroll`, `admin.growth-circles.reapply-bracket`).

- [ ] **Step 4: Format and commit**

```bash
./vendor/bin/pint routes/web.php
git add routes/web.php
git commit -m "Add admin routes for Growth Circles"
```

### Task 5.3: Create the admin index view (settings + enrollments)

**Files:**
- Create: `resources/views/admin/growth-circles/index.blade.php`

Mirrors the structure of the DD admin partial (`resources/views/admin/accounts/direct_deposit.blade.php`): settings card on top, enrollments table below.

- [ ] **Step 1: Write the Blade template**

```blade
@extends('layouts.admin')

@section('content')
    <div class="space-y-6">
        @can('view-growth-circles')

            {{-- Settings card --}}
            <x-card title="Growth Circles Settings">
                <form method="POST" action="{{ route('admin.growth-circles.settings') }}">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-base-content">Growth Circles Tax ID</span>
                            <input
                                type="number"
                                name="growth_circles_tax_id"
                                value="{{ old('growth_circles_tax_id', $taxId) }}"
                                class="input input-bordered w-full"
                                @cannot('manage-growth-circles') disabled @endcannot
                                required
                            >
                            <span class="block text-xs text-base-content/60">
                                The in-game tax bracket ID with 100% retention across all resources. Members enrolled in Growth Circles will be assigned this bracket.
                            </span>
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-base-content">Fallback Tax ID</span>
                            <input
                                type="number"
                                name="growth_circles_fallback_tax_id"
                                value="{{ old('growth_circles_fallback_tax_id', $fallbackTaxId) }}"
                                class="input input-bordered w-full"
                                @cannot('manage-growth-circles') disabled @endcannot
                                required
                            >
                            <span class="block text-xs text-base-content/60">
                                Used when a member disenrolls and their original bracket cannot be restored. Both brackets must already exist in the P&W tax bracket list.
                            </span>
                        </label>
                    </div>
                    @can('manage-growth-circles')
                        <x-button label="Save Settings" type="submit" icon="o-check" class="btn-primary" />
                    @endcan
                </form>
            </x-card>

            {{-- Enrollments table --}}
            <x-card title="Enrollments">
                @if ($rows->isEmpty())
                    <p class="text-base-content/60 text-sm">No nations are currently enrolled in Growth Circles.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="table table-sm w-full">
                            <thead>
                            <tr>
                                <th>Nation</th>
                                <th class="text-right">Cities</th>
                                <th>Account</th>
                                <th>Enrolled</th>
                                <th>Last distribution</th>
                                <th class="text-right">7-day food</th>
                                <th class="text-right">7-day uranium</th>
                                <th>Status</th>
                                @can('manage-growth-circles')
                                    <th class="text-right">Actions</th>
                                @endcan
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($rows as $row)
                                @php
                                    $enrollment = $row['enrollment'];
                                    $nation = $enrollment->nation;
                                    $eligibility = $row['eligibility'];
                                @endphp
                                <tr>
                                    <td>{{ $nation?->nation_name ?? '(deleted)' }}</td>
                                    <td class="text-right">{{ $nation?->num_cities ?? '—' }}</td>
                                    <td>{{ $enrollment->account?->name ?? '(deleted account)' }}</td>
                                    <td>{{ $enrollment->enrolled_at?->toDateString() }}</td>
                                    <td>
                                        @if ($row['last'])
                                            {{ $row['last']->cycle_date->toDateString() }}
                                            <span class="text-xs text-base-content/60">
                                                ({{ number_format($row['last']->food, 2) }} F, {{ number_format($row['last']->uranium, 2) }} U)
                                            </span>
                                        @else
                                            <span class="text-base-content/50">—</span>
                                        @endif
                                    </td>
                                    <td class="text-right">{{ number_format($row['seven_day_food'], 2) }}</td>
                                    <td class="text-right">{{ number_format($row['seven_day_uranium'], 2) }}</td>
                                    <td>
                                        @if ($eligibility['eligible'])
                                            <span class="badge badge-success badge-sm">Active</span>
                                        @else
                                            <span class="badge badge-warning badge-sm" title="{{ $eligibility['reason'] }}">Paused</span>
                                            <span class="block text-xs text-base-content/60">{{ $eligibility['reason'] }}</span>
                                        @endif
                                    </td>
                                    @can('manage-growth-circles')
                                        <td class="text-right">
                                            <div class="flex justify-end gap-2">
                                                <form method="POST"
                                                      action="{{ route('admin.growth-circles.force-disenroll', $nation?->id ?? 0) }}"
                                                      onsubmit="return confirm('Force-disenroll {{ $nation?->nation_name ?? 'this nation' }} from Growth Circles?');"
                                                      @if (! $nation) style="display:none" @endif>
                                                    @csrf
                                                    <button class="btn btn-xs btn-error">Force-disenroll</button>
                                                </form>
                                                @can('view-diagnostic-info')
                                                    <form method="POST"
                                                          action="{{ route('admin.growth-circles.reapply-bracket', $nation?->id ?? 0) }}"
                                                          @if (! $nation) style="display:none" @endif>
                                                        @csrf
                                                        <button class="btn btn-xs btn-outline">Reapply bracket</button>
                                                    </form>
                                                @endcan
                                            </div>
                                        </td>
                                    @endcan
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="mt-4 flex justify-end">
                    <a href="{{ route('admin.growth-circles.history') }}" class="link link-primary text-sm">
                        View distribution history →
                    </a>
                </div>
            </x-card>

        @endcan
    </div>
@endsection
```

- [ ] **Step 2: Browser smoke test**

Pre-req: log in as a user with the `view-growth-circles` permission.

Visit `/admin/growth-circles`. Expected:
- Page renders without exception.
- The "Growth Circles Settings" card shows two number inputs (currently `0` if unconfigured).
- The "Enrollments" card shows "No nations are currently enrolled in Growth Circles."
- A link "View distribution history →" appears at the bottom.

- [ ] **Step 3: Save settings smoke test**

Enter real bracket IDs (look them up in `tax_brackets` if needed), click Save. Expected:
- Success flash: "Growth Circles settings saved."
- Reload the page; both inputs retain the saved values.
- Verify the underlying setting:
  ```bash
  php artisan tinker --execute="dump(\App\Services\SettingService::getGrowthCirclesTaxId(), \App\Services\SettingService::getGrowthCirclesFallbackTaxId());"
  ```
  Both should match what was entered.

- [ ] **Step 4: Format and commit**

```bash
./vendor/bin/pint resources/views/admin/growth-circles/index.blade.php
git add resources/views/admin/growth-circles/index.blade.php
git commit -m "Add admin Growth Circles index view"
```

### Task 5.4: Create the admin distribution-history view

**Files:**
- Create: `resources/views/admin/growth-circles/history.blade.php`

- [ ] **Step 1: Write the Blade template**

```blade
@extends('layouts.admin')

@section('content')
    <div class="space-y-6">
        @can('view-growth-circles')
            <x-card title="Growth Circles Distribution History">
                <form method="GET" action="{{ route('admin.growth-circles.history') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
                    <label class="block">
                        <span class="text-xs font-medium text-base-content/70">From</span>
                        <input type="date" name="from" value="{{ request('from') }}" class="input input-bordered input-sm w-full">
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-base-content/70">To</span>
                        <input type="date" name="to" value="{{ request('to') }}" class="input input-bordered input-sm w-full">
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-base-content/70">Nation ID</span>
                        <input type="number" name="nation_id" value="{{ request('nation_id') }}" class="input input-bordered input-sm w-full">
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-base-content/70">Account ID</span>
                        <input type="number" name="account_id" value="{{ request('account_id') }}" class="input input-bordered input-sm w-full">
                    </label>
                    <div class="md:col-span-4 flex gap-2">
                        <x-button type="submit" label="Filter" class="btn-primary btn-sm" />
                        <a href="{{ route('admin.growth-circles.history') }}" class="btn btn-sm btn-ghost">Clear</a>
                        <a href="{{ route('admin.growth-circles.index') }}" class="btn btn-sm btn-outline ml-auto">← Back to enrollments</a>
                    </div>
                </form>

                @if ($rows->isEmpty())
                    <p class="text-base-content/60 text-sm">No distributions match the current filter.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="table table-sm w-full">
                            <thead>
                            <tr>
                                <th>Cycle</th>
                                <th>Nation</th>
                                <th>Account</th>
                                <th class="text-right">Food</th>
                                <th class="text-right">Uranium</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td>{{ $row->cycle_date->toDateString() }}</td>
                                    <td>{{ $row->nation?->nation_name ?? '(deleted)' }}</td>
                                    <td>{{ $row->account?->name ?? '(deleted)' }}</td>
                                    <td class="text-right">{{ number_format($row->food, 2) }}</td>
                                    <td class="text-right">{{ number_format($row->uranium, 2) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4">
                        {{ $rows->links() }}
                    </div>
                @endif
            </x-card>
        @endcan
    </div>
@endsection
```

Note: spec §10.3 mentions a CSV export. Deferred to a follow-up — not included in this chunk to keep scope focused. The history table and filters are the primary deliverable; CSV export can be added once the page is in production use and admins ask for it.

- [ ] **Step 2: Browser smoke test — empty state**

Visit `/admin/growth-circles/history`. Expected:
- Page renders.
- Filter form shows From/To/Nation/Account inputs and Filter/Clear buttons.
- Body says "No distributions match the current filter." (or shows real rows if the dev DB has any).
- A "← Back to enrollments" link returns to the index.

- [ ] **Step 3: Browser smoke test — populated state**

Pre-req: enroll a nation and run `php artisan growth-circles:distribute` to create rows. Visit `/admin/growth-circles/history`. Expected:
- Distribution rows appear with cycle date, nation name, account name, food, uranium.
- Apply a date filter and submit; the table updates accordingly.
- Pagination appears if more than 50 rows.

Cleanup:
```bash
php artisan tinker --execute="\App\Models\GrowthCircleDistribution::query()->delete(); app(\App\Services\GrowthCircleService::class)->disenroll(\App\Models\Nation::query()->where('num_cities', '>', 0)->first());"
```

- [ ] **Step 4: Format and commit**

```bash
./vendor/bin/pint resources/views/admin/growth-circles/history.blade.php
git add resources/views/admin/growth-circles/history.blade.php
git commit -m "Add admin Growth Circles distribution-history view"
```

### Task 5.5: Sanity check the chunk

- [ ] **Step 1: Verify all 7 GC routes resolve**

```bash
php artisan route:list 2>&1 | grep growth-circles
```
Expected: 7 lines total — 2 user (enroll, disenroll) and 5 admin (index, history, settings, force-disenroll, reapply-bracket).

- [ ] **Step 2: Verify both admin pages load**

Hit `/admin/growth-circles` and `/admin/growth-circles/history` in the browser as a user with `view-growth-circles`. Both must render without errors.

Hit them as a user *without* the permission. Both must respond with 403.

- [ ] **Step 3: End-to-end smoke**

1. Log in as admin user with all GC permissions plus `view-diagnostic-info`.
2. Save settings using real bracket IDs.
3. Switch to a member user, enroll in Growth Circles via the user card from Chunk 4.
4. Switch back to admin; confirm the new enrollment appears on `/admin/growth-circles` with status `Active`, last-distribution `—`, 7-day totals `0.00`.
5. Click "Force-disenroll" on the row; confirm the enrollment disappears.
6. Re-enroll; click "Reapply bracket" on the row; confirm a success flash and an audit log entry with action `bracket_reapplied`.

- [ ] **Step 4: Confirm clean working tree**

```bash
git status
```
Expected: `nothing to commit, working tree clean`.

End of Chunk 5.

---

## Final Sanity Check

After all chunks land:

- [ ] All 7 routes resolve (`php artisan route:list | grep growth-circles`).
- [ ] `php artisan growth-circles:distribute` runs cleanly with empty + non-empty enrollment sets.
- [ ] `php artisan schedule:list | grep growth-circles` shows `0 3 * * *` UTC.
- [ ] Member-side: account card renders three states correctly (not enrolled, enrolled active, enrolled paused) and switches between DD and GC preserve original `previous_tax_id`.
- [ ] Admin-side: settings save, enrollments table renders with live status, force-disenroll and reapply-bracket actions work.
- [ ] No stray uncommitted changes; all PHP changes have been formatted with Pint.
- [ ] Manual QA matches the verification list in spec §15.


