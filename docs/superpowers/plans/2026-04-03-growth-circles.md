# Growth Circles Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the Growth Circles program — an opt-in feature where alliance members are taxed at 100% in exchange for automatic food and uranium distributions, with abuse detection and admin management tools.

**Architecture:** A dedicated `GrowthCircleService` handles enrollment (bracket assignment) and removal (bracket restoration). A scheduled command runs every 2 hours to distribute food/uranium to enrolled nations via `AccountService::dispatchWithdrawal()`, then runs a second pass for abuse detection. Admin and user UI layers are thin controllers backed by this service.

**Tech Stack:** Laravel 12, PHP 8.4, Eloquent ORM, Laravel Queue, `BankService`/`TransactionService`/`AccountService` for P&W bank sends, `TaxBracketService` for bracket assignment, Discord notifications via `DiscordQueueChannel`.

**Note on testing:** This project has no automated tests by policy. Each task ends with a Pint format step and a manual verification note instead of test steps.

---

## Chunk 1: Foundation — Migrations, Models, Settings, Permissions

### Task 1: Create `growth_circle_enrollments` migration

**Files:**
- Create: `database/migrations/2026_04_03_000001_create_growth_circle_enrollments_table.php`

- [ ] **Step 1: Generate migration**

```bash
php artisan make:migration create_growth_circle_enrollments_table --no-interaction
```

- [ ] **Step 2: Write migration content**

Replace the generated file body with:

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
            $table->integer('previous_tax_id')->nullable();
            $table->boolean('suspended')->default(false);
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason')->nullable();
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamps();

            $table->foreign('nation_id')->references('id')->on('nations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_circle_enrollments');
    }
};
```

- [ ] **Step 3: Run migration**

```bash
php artisan migrate --no-interaction
```

Expected: `Migrating: ..._create_growth_circle_enrollments_table` then `Migrated`.

---

### Task 2: Create `growth_circle_distributions` migration

**Files:**
- Create: `database/migrations/2026_04_03_000002_create_growth_circle_distributions_table.php`

- [ ] **Step 1: Generate migration**

```bash
php artisan make:migration create_growth_circle_distributions_table --no-interaction
```

- [ ] **Step 2: Write migration content**

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
            $table->float('food_sent')->default(0);
            $table->float('uranium_sent')->default(0);
            $table->float('food_level_before')->default(0);
            $table->float('uranium_level_before')->default(0);
            $table->unsignedInteger('city_count')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('nation_id')->references('id')->on('nations')->cascadeOnDelete();
            $table->index(['nation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_circle_distributions');
    }
};
```

- [ ] **Step 3: Run migration**

```bash
php artisan migrate --no-interaction
```

Expected: migrated successfully.

---

### Task 3: Create `GrowthCircleEnrollment` model

**Files:**
- Create: `app/Models/GrowthCircleEnrollment.php`

- [ ] **Step 1: Generate model**

```bash
php artisan make:model GrowthCircleEnrollment --no-interaction
```

- [ ] **Step 2: Write model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrowthCircleEnrollment extends Model
{
    protected $fillable = [
        'nation_id',
        'previous_tax_id',
        'suspended',
        'suspended_at',
        'suspended_reason',
        'enrolled_at',
    ];

    protected function casts(): array
    {
        return [
            'suspended' => 'bool',
            'suspended_at' => 'datetime',
            'enrolled_at' => 'datetime',
        ];
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }
}
```

- [ ] **Step 3: Format**

```bash
./vendor/bin/pint app/Models/GrowthCircleEnrollment.php
```

---

### Task 4: Create `GrowthCircleDistribution` model

**Files:**
- Create: `app/Models/GrowthCircleDistribution.php`

- [ ] **Step 1: Generate model**

```bash
php artisan make:model GrowthCircleDistribution --no-interaction
```

- [ ] **Step 2: Write model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrowthCircleDistribution extends Model
{
    // Append-only log — no updated_at column
    const UPDATED_AT = null;

    protected $fillable = [
        'nation_id',
        'food_sent',
        'uranium_sent',
        'food_level_before',
        'uranium_level_before',
        'city_count',
    ];

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }
}
```

- [ ] **Step 3: Format**

```bash
./vendor/bin/pint app/Models/GrowthCircleDistribution.php
```

---

### Task 5: Add Growth Circle settings to `SettingService`

**Files:**
- Modify: `app/Services/SettingService.php`

- [ ] **Step 1: Add 7 new static methods after `setBackupsEnabled()`**

Open `app/Services/SettingService.php`. After the `setBackupsEnabled()` method (around line 237), add:

```php
public static function isGrowthCirclesEnabled(): bool
{
    $value = self::getValue('growth_circles_enabled');

    if (is_null($value)) {
        self::setGrowthCirclesEnabled(false);

        return false;
    }

    return (bool) $value;
}

public static function setGrowthCirclesEnabled(bool $enabled): void
{
    self::setValue('growth_circles_enabled', $enabled ? 1 : 0);
}

public static function getGrowthCircleTaxId(): int
{
    return (int) (self::getValue('growth_circle_tax_id') ?? 0);
}

public static function setGrowthCircleTaxId(int $taxId): void
{
    self::setValue('growth_circle_tax_id', $taxId);
}

public static function getGrowthCircleFallbackTaxId(): int
{
    return (int) (self::getValue('growth_circle_fallback_tax_id') ?? 0);
}

public static function setGrowthCircleFallbackTaxId(int $taxId): void
{
    self::setValue('growth_circle_fallback_tax_id', $taxId);
}

public static function getGrowthCircleSourceAccountId(): int
{
    return (int) (self::getValue('growth_circle_source_account_id') ?? 0);
}

public static function setGrowthCircleSourceAccountId(int $accountId): void
{
    self::setValue('growth_circle_source_account_id', $accountId);
}

public static function getGrowthCircleFoodPerCity(): int
{
    return (int) (self::getValue('growth_circle_food_per_city') ?? 0);
}

public static function setGrowthCircleFoodPerCity(int $amount): void
{
    self::setValue('growth_circle_food_per_city', $amount);
}

public static function getGrowthCircleUraniumPerCity(): int
{
    return (int) (self::getValue('growth_circle_uranium_per_city') ?? 0);
}

public static function setGrowthCircleUraniumPerCity(int $amount): void
{
    self::setValue('growth_circle_uranium_per_city', $amount);
}

public static function getGrowthCircleDiscordChannelId(): string
{
    return (string) (self::getValue('growth_circle_discord_channel_id') ?? '');
}

public static function setGrowthCircleDiscordChannelId(string $channelId): void
{
    self::setValue('growth_circle_discord_channel_id', $channelId);
}
```

- [ ] **Step 2: Format**

```bash
./vendor/bin/pint app/Services/SettingService.php
```

---

### Task 6: Register new permissions

**Files:**
- Modify: `config/permissions.php`

- [ ] **Step 1: Add two entries to the array**

Open `config/permissions.php`. Add before the closing `];`:

```php
    'view-growth-circles',
    'manage-growth-circles',
```

- [ ] **Manual verification:** Visit Admin → Roles in the browser. The two new permissions should appear in the permission list for editing roles.

---

## Chunk 2: Service Layer and Scheduled Command

### Task 7: Create `GrowthCircleService`

**Files:**
- Create: `app/Services/GrowthCircleService.php`

- [ ] **Step 1: Create the file**

```php
<?php

namespace App\Services;

use App\Models\Account;
use App\Models\GrowthCircleDistribution;
use App\Models\GrowthCircleEnrollment;
use App\Models\Nation;
use App\Notifications\Channels\DiscordQueueChannel;
use App\Notifications\GrowthCircleAbuseSuspendedNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class GrowthCircleService
{
    /**
     * Per-city per-hour consumption rates used for abuse detection.
     * These are intentional overestimates to reduce false positives.
     */
    private const FOOD_CONSUMPTION_PER_CITY_PER_HOUR = 200.0;

    private const URANIUM_CONSUMPTION_PER_CITY_PER_HOUR = 1.5;

    /**
     * Enroll a nation in Growth Circles.
     * Stores their current tax bracket, creates the enrollment record,
     * and assigns the 100% Growth Circle tax bracket.
     *
     * @throws ValidationException
     */
    public function enroll(Nation $nation): GrowthCircleEnrollment
    {
        try {
            $enrollment = GrowthCircleEnrollment::updateOrCreate(
                ['nation_id' => $nation->id],
                [
                    'previous_tax_id' => $nation->tax_id,
                    'suspended' => false,
                    'suspended_at' => null,
                    'suspended_reason' => null,
                    'enrolled_at' => now(),
                ]
            );
        } catch (QueryException $e) {
            throw ValidationException::withMessages([
                'You are already enrolled in Growth Circles.',
            ]);
        }

        $taxId = SettingService::getGrowthCircleTaxId();

        if ($taxId > 0) {
            $mutation = new TaxBracketService;
            $mutation->id = $taxId;
            $mutation->target_id = $nation->id;
            $mutation->send();
        }

        return $enrollment;
    }

    /**
     * Remove a nation from Growth Circles (admin only).
     * Restores their previous tax bracket and deletes the enrollment.
     */
    public function remove(Nation $nation): void
    {
        $enrollment = GrowthCircleEnrollment::where('nation_id', $nation->id)->first();

        if (! $enrollment) {
            return;
        }

        $bracketId = ($enrollment->previous_tax_id !== null && $enrollment->previous_tax_id > 0)
            ? $enrollment->previous_tax_id
            : SettingService::getGrowthCircleFallbackTaxId();

        if ($bracketId > 0) {
            $mutation = new TaxBracketService;
            $mutation->id = $bracketId;
            $mutation->target_id = $nation->id;
            $mutation->send();
        }

        $enrollment->delete();
    }

    /**
     * Clear a suspension on an enrollment, re-activating distributions.
     */
    public function clearSuspension(GrowthCircleEnrollment $enrollment): void
    {
        $enrollment->update([
            'suspended' => false,
            'suspended_at' => null,
            'suspended_reason' => null,
        ]);
    }

    /**
     * Run the distribution pass: top up food and uranium for all active enrollments.
     *
     * @return array{processed: int, sent: int, skipped: int}
     */
    public function distribute(): array
    {
        $sourceAccountId = SettingService::getGrowthCircleSourceAccountId();
        $foodPerCity = SettingService::getGrowthCircleFoodPerCity();
        $uraniumPerCity = SettingService::getGrowthCircleUraniumPerCity();

        $summary = ['processed' => 0, 'sent' => 0, 'skipped' => 0];

        GrowthCircleEnrollment::query()
            ->where('suspended', false)
            ->with('nation')
            ->chunkById(100, function ($enrollments) use (
                $sourceAccountId,
                $foodPerCity,
                $uraniumPerCity,
                &$summary
            ): void {
                foreach ($enrollments as $enrollment) {
                    $summary['processed']++;

                    $nation = $enrollment->nation;

                    if (! $nation) {
                        $summary['skipped']++;

                        continue;
                    }

                    $this->distributeToNation(
                        $nation,
                        $sourceAccountId,
                        $foodPerCity,
                        $uraniumPerCity,
                        $summary
                    );
                }
            });

        return $summary;
    }

    /**
     * Run the abuse detection pass after all distributions are complete.
     * Suspends nations whose resource levels are suspiciously low.
     */
    public function runAbuseDetection(): void
    {
        $windowStart = now()->subHours(24);

        GrowthCircleEnrollment::query()
            ->where('suspended', false)
            ->with('nation')
            ->chunkById(100, function ($enrollments) use ($windowStart): void {
                foreach ($enrollments as $enrollment) {
                    $nation = $enrollment->nation;

                    if (! $nation) {
                        continue;
                    }

                    $resources = $nation->resources;

                    if (! $resources) {
                        continue;
                    }

                    $totals = GrowthCircleDistribution::query()
                        ->where('nation_id', $nation->id)
                        ->where('created_at', '>=', $windowStart)
                        ->selectRaw('SUM(food_sent) as total_food, SUM(uranium_sent) as total_uranium')
                        ->first();

                    if (! $totals) {
                        continue;
                    }

                    $cityCount = (int) $nation->num_cities;
                    $hoursInWindow = 24;

                    $estimatedFoodConsumed = $cityCount * self::FOOD_CONSUMPTION_PER_CITY_PER_HOUR * $hoursInWindow;
                    $estimatedUraniumConsumed = $cityCount * self::URANIUM_CONSUMPTION_PER_CITY_PER_HOUR * $hoursInWindow;

                    $expectedFoodFloor = (float) $totals->total_food - $estimatedFoodConsumed;
                    $expectedUraniumFloor = (float) $totals->total_uranium - $estimatedUraniumConsumed;

                    $currentFood = (float) ($resources->food ?? 0);
                    $currentUranium = (float) ($resources->uranium ?? 0);

                    $foodAbuse = $expectedFoodFloor > 0 && $currentFood < ($expectedFoodFloor * 0.20);
                    $uraniumAbuse = $expectedUraniumFloor > 0 && $currentUranium < ($expectedUraniumFloor * 0.20);

                    if ($foodAbuse || $uraniumAbuse) {
                        $this->suspendForAbuse($enrollment, $nation);
                    }
                }
            });
    }

    private function distributeToNation(
        Nation $nation,
        int $sourceAccountId,
        int $foodPerCity,
        int $uraniumPerCity,
        array &$summary
    ): void {
        // Refresh resources if stale (>3 hours)
        $resources = $nation->resources;
        $requiresRefresh = is_null($resources)
            || ($resources->updated_at?->lt(now()->subHours(3)) ?? true);

        if ($requiresRefresh) {
            try {
                $graphQLNation = NationQueryService::getNationById($nation->id);
                Nation::updateFromAPI($graphQLNation);
                $nation->refresh();
                $resources = $nation->resources;
            } catch (\Throwable $e) {
                Log::warning("GrowthCircles: resource refresh failed for nation {$nation->id}: {$e->getMessage()}");
                $summary['skipped']++;

                return;
            }
        }

        if (! $resources) {
            $summary['skipped']++;

            return;
        }

        $cityCount = (int) $nation->num_cities;
        $foodTarget = $cityCount * $foodPerCity;
        $uraniumTarget = $cityCount * $uraniumPerCity;

        $currentFood = (float) ($resources->food ?? 0);
        $currentUranium = (float) ($resources->uranium ?? 0);

        $foodToSend = max(0.0, $foodTarget - $currentFood);
        $uraniumToSend = max(0.0, $uraniumTarget - $currentUranium);

        $sent = false;

        if ($foodToSend > 0 || $uraniumToSend > 0) {
            $transaction = null;
            $lockedAccount = null;

            DB::transaction(function () use (
                $nation,
                $sourceAccountId,
                &$foodToSend,
                &$uraniumToSend,
                &$transaction,
                &$lockedAccount
            ): void {
                $lockedAccount = Account::query()->lockForUpdate()->find($sourceAccountId);

                if (! $lockedAccount) {
                    Log::warning("GrowthCircles: source account {$sourceAccountId} not found.");

                    return;
                }

                // Clamp to available balance
                $foodToSend = min($foodToSend, (float) floor($lockedAccount->food));
                $uraniumToSend = min($uraniumToSend, (float) floor($lockedAccount->uranium));

                if ($foodToSend <= 0 && $uraniumToSend <= 0) {
                    Log::warning("GrowthCircles: source account {$sourceAccountId} has insufficient food/uranium for nation {$nation->id}.");

                    return;
                }

                $lockedAccount->food -= $foodToSend;
                $lockedAccount->uranium -= $uraniumToSend;
                $lockedAccount->save();

                $resources = [];
                if ($foodToSend > 0) {
                    $resources['food'] = $foodToSend;
                }
                if ($uraniumToSend > 0) {
                    $resources['uranium'] = $uraniumToSend;
                }

                $transaction = TransactionService::createTransaction(
                    resources: $resources,
                    nation_id: $nation->id,
                    fromAccountId: $lockedAccount->id,
                    transactionType: 'withdrawal',
                    isPending: true,
                    note: 'Growth Circle distribution',
                );
            });

            if ($transaction && $lockedAccount) {
                AccountService::dispatchWithdrawal($transaction, $lockedAccount);
                $sent = true;
            }
        }

        GrowthCircleDistribution::create([
            'nation_id' => $nation->id,
            'food_sent' => $foodToSend,
            'uranium_sent' => $uraniumToSend,
            'food_level_before' => $currentFood,
            'uranium_level_before' => $currentUranium,
            'city_count' => $cityCount,
        ]);

        if ($sent) {
            $summary['sent']++;
        }
    }

    private function suspendForAbuse(GrowthCircleEnrollment $enrollment, Nation $nation): void
    {
        $enrollment->update([
            'suspended' => true,
            'suspended_at' => now(),
            'suspended_reason' => 'Resource levels significantly below expected after distributions. Possible selling detected.',
        ]);

        $channelId = SettingService::getGrowthCircleDiscordChannelId();

        if (empty($channelId)) {
            Log::warning("GrowthCircles: abuse detected for nation {$nation->id} but no Discord channel configured.");

            return;
        }

        Notification::route(DiscordQueueChannel::class, 'discord-bot')
            ->notify(new GrowthCircleAbuseSuspendedNotification($channelId, $nation));
    }
}
```

- [ ] **Step 2: Format**

```bash
./vendor/bin/pint app/Services/GrowthCircleService.php
```

---

### Task 8: Create `GrowthCircleAbuseSuspendedNotification`

**Files:**
- Create: `app/Notifications/GrowthCircleAbuseSuspendedNotification.php`

- [ ] **Step 1: Create the file**

```php
<?php

namespace App\Notifications;

use App\Models\Nation;
use App\Notifications\Channels\DiscordQueueChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class GrowthCircleAbuseSuspendedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $channelId,
        private readonly Nation $nation,
    ) {}

    /**
     * @return array<int, class-string<DiscordQueueChannel>>
     */
    public function via(object $notifiable): array
    {
        return [DiscordQueueChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDiscordBot(object $notifiable): array
    {
        return [
            'action' => 'GROWTH_CIRCLE_ABUSE_ALERT',
            'channel_id' => $this->channelId,
            'payload' => [
                'channel_id' => $this->channelId,
                'event_type' => 'growth_circle_abuse_suspension',
                'detected_at' => now()->toIso8601String(),
                'nation' => [
                    'id' => $this->nation->id,
                    'nation_name' => $this->nation->nation_name,
                    'leader_name' => $this->nation->leader_name,
                    'num_cities' => $this->nation->num_cities,
                ],
            ],
        ];
    }
}
```

- [ ] **Step 2: Format**

```bash
./vendor/bin/pint app/Notifications/GrowthCircleAbuseSuspendedNotification.php
```

---

### Task 9: Create `DistributeGrowthCircles` Artisan command

**Files:**
- Create: `app/Console/Commands/DistributeGrowthCircles.php`

- [ ] **Step 1: Generate command**

```bash
php artisan make:command DistributeGrowthCircles --no-interaction
```

- [ ] **Step 2: Write command**

```php
<?php

namespace App\Console\Commands;

use App\Services\GrowthCircleService;
use App\Services\PWHealthService;
use App\Services\SettingService;
use Illuminate\Console\Command;

class DistributeGrowthCircles extends Command
{
    protected $signature = 'growth-circles:distribute';

    protected $description = 'Distribute food and uranium to enrolled Growth Circle members, then run abuse detection.';

    public function handle(GrowthCircleService $service, PWHealthService $healthService): int
    {
        if (! SettingService::isGrowthCirclesEnabled()) {
            $this->info('Growth Circles is disabled. Skipping.');

            return self::SUCCESS;
        }

        if (! $healthService->isUp()) {
            $this->info('P&W API is down. Skipping Growth Circles distribution.');

            return self::SUCCESS;
        }

        $this->info('Starting Growth Circles distribution pass...');
        $summary = $service->distribute();
        $this->info("Distribution complete. Processed: {$summary['processed']}, Sent: {$summary['sent']}, Skipped: {$summary['skipped']}");

        $this->info('Starting abuse detection pass...');
        $service->runAbuseDetection();
        $this->info('Abuse detection complete.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 3: Format**

```bash
./vendor/bin/pint app/Console/Commands/DistributeGrowthCircles.php
```

- [ ] **Step 4: Register schedule in `routes/console.php`**

Open `routes/console.php`. After the `auto:withdraw` schedule entry, add:

```php
Schedule::command('growth-circles:distribute')
    ->everyTwoHours()
    ->runInBackground()
    ->withoutOverlapping(110)
    ->when($whenPWUp)
    ->when(fn () => SettingService::isGrowthCirclesEnabled());
```

Also add the import at the top of the file if `SettingService` is not already imported:

```php
use App\Services\SettingService;
```

- [ ] **Step 5: Verify command registers**

```bash
php artisan list | grep growth
```

Expected output: `growth-circles:distribute`

- [ ] **Step 6: Format**

```bash
./vendor/bin/pint routes/console.php
```

---

## Chunk 3: HTTP Layer and UI

### Task 10: Create user `GrowthCircleController`

**Files:**
- Create: `app/Http/Controllers/GrowthCircleController.php`

- [ ] **Step 1: Create the file**

```php
<?php

namespace App\Http\Controllers;

use App\Services\GrowthCircleService;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class GrowthCircleController extends Controller
{
    public function __construct(private readonly GrowthCircleService $service) {}

    public function enroll(): RedirectResponse
    {
        if (! SettingService::isGrowthCirclesEnabled()) {
            return back()->with([
                'alert-message' => 'Growth Circles is not currently available.',
                'alert-type' => 'error',
            ]);
        }

        $nation = Auth::user()->nation;

        if (! $nation) {
            return back()->with([
                'alert-message' => 'No nation linked to your account.',
                'alert-type' => 'error',
            ]);
        }

        try {
            $this->service->enroll($nation);
        } catch (ValidationException $e) {
            return back()->with([
                'alert-message' => $e->getMessage(),
                'alert-type' => 'error',
            ]);
        }

        return back()->with([
            'alert-message' => 'You have been enrolled in Growth Circles. Your tax bracket will update shortly.',
            'alert-type' => 'success',
        ]);
    }
}
```

- [ ] **Step 2: Format**

```bash
./vendor/bin/pint app/Http/Controllers/GrowthCircleController.php
```

- [ ] **Step 3: Add user route to `routes/web.php`**

Inside the `auth` middleware group (near the `dd.enroll` route around line 150), add:

```php
Route::post('/growth-circles/enroll', [GrowthCircleController::class, 'enroll'])
    ->name('growth-circles.enroll')
    ->middleware([BlockWhenPWDown::class]);
```

Add the import at the top of `routes/web.php`:

```php
use App\Http\Controllers\GrowthCircleController;
```

---

### Task 11: Create admin `GrowthCircleController`

**Files:**
- Create: `app/Http/Controllers/Admin/GrowthCircleController.php`

- [ ] **Step 1: Create the file**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GrowthCircleDistribution;
use App\Models\GrowthCircleEnrollment;
use App\Models\Nation;
use App\Services\GrowthCircleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class GrowthCircleController extends Controller
{
    public function __construct(private readonly GrowthCircleService $service) {}

    public function index(): View
    {
        $this->authorize('view-growth-circles');

        $enrollments = GrowthCircleEnrollment::query()
            ->with('nation')
            ->orderByDesc('suspended')
            ->orderBy('enrolled_at')
            ->paginate(50);

        return view('admin.growth-circles.index', compact('enrollments'));
    }

    public function remove(Nation $nation): RedirectResponse
    {
        $this->authorize('manage-growth-circles');

        $this->service->remove($nation);

        return redirect()->route('admin.growth-circles.index')->with([
            'alert-message' => "{$nation->nation_name} has been removed from Growth Circles.",
            'alert-type' => 'success',
        ]);
    }

    public function clearSuspension(GrowthCircleEnrollment $enrollment): RedirectResponse
    {
        $this->authorize('manage-growth-circles');

        $this->service->clearSuspension($enrollment);

        return redirect()->route('admin.growth-circles.index')->with([
            'alert-message' => 'Suspension cleared. Distributions will resume on the next cycle.',
            'alert-type' => 'success',
        ]);
    }

    public function distributions(Nation $nation): View
    {
        $this->authorize('view-growth-circles');

        $distributions = GrowthCircleDistribution::query()
            ->where('nation_id', $nation->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return view('admin.growth-circles.distributions', compact('nation', 'distributions'));
    }
}
```

- [ ] **Step 2: Format**

```bash
./vendor/bin/pint app/Http/Controllers/Admin/GrowthCircleController.php
```

- [ ] **Step 3: Add admin routes to `routes/web.php`**

At the top of `routes/web.php`, add the import alongside existing admin controller imports:

```php
use App\Http\Controllers\Admin\GrowthCircleController as AdminGrowthCircleController;
```

Then inside the admin middleware group (after the war-aid routes), add:

```php
Route::get('/admin/growth-circles', [AdminGrowthCircleController::class, 'index'])
    ->name('admin.growth-circles.index');
Route::delete('/admin/growth-circles/{nation}/remove', [AdminGrowthCircleController::class, 'remove'])
    ->name('admin.growth-circles.remove');
Route::post('/admin/growth-circles/{enrollment}/clear-suspension', [AdminGrowthCircleController::class, 'clearSuspension'])
    ->name('admin.growth-circles.clear-suspension');
Route::get('/admin/growth-circles/{nation}/distributions', [AdminGrowthCircleController::class, 'distributions'])
    ->name('admin.growth-circles.distributions');
```

---

### Task 12: Add Growth Circles settings to `SettingsController` and view

**Files:**
- Modify: `app/Http/Controllers/Admin/SettingsController.php`
- Modify: `resources/views/admin/settings.blade.php`

- [ ] **Step 1: Add `index()` variables to `SettingsController::index()`**

In `SettingsController::index()`, add these variables to the `$data` array passed to the view (after the `autoWithdrawEnabled` line):

```php
'growthCirclesEnabled' => SettingService::isGrowthCirclesEnabled(),
'growthCircleTaxId' => SettingService::getGrowthCircleTaxId(),
'growthCircleFallbackTaxId' => SettingService::getGrowthCircleFallbackTaxId(),
'growthCircleSourceAccountId' => SettingService::getGrowthCircleSourceAccountId(),
'growthCircleFoodPerCity' => SettingService::getGrowthCircleFoodPerCity(),
'growthCircleUraniumPerCity' => SettingService::getGrowthCircleUraniumPerCity(),
'growthCircleDiscordChannelId' => SettingService::getGrowthCircleDiscordChannelId(),
'sourceAccounts' => \App\Models\Account::query()->whereNull('nation_id')->orderBy('name')->get(['id', 'name']),
```

- [ ] **Step 2: Add `updateGrowthCircles()` method to `SettingsController`**

After the `updateAutoWithdraw()` method, add:

```php
public function updateGrowthCircles(Request $request): RedirectResponse
{
    $this->authorize('manage-growth-circles');

    $validated = $request->validate([
        'growth_circles_enabled' => ['required', 'boolean'],
        'growth_circle_tax_id' => ['required', 'integer', 'min:0'],
        'growth_circle_fallback_tax_id' => ['required', 'integer', 'min:0'],
        'growth_circle_source_account_id' => ['required', 'integer', 'min:0'],
        'growth_circle_food_per_city' => ['required', 'integer', 'min:0'],
        'growth_circle_uranium_per_city' => ['required', 'integer', 'min:0'],
        'growth_circle_discord_channel_id' => ['nullable', 'string', 'max:30'],
    ]);

    SettingService::setGrowthCirclesEnabled((bool) $validated['growth_circles_enabled']);
    SettingService::setGrowthCircleTaxId((int) $validated['growth_circle_tax_id']);
    SettingService::setGrowthCircleFallbackTaxId((int) $validated['growth_circle_fallback_tax_id']);
    SettingService::setGrowthCircleSourceAccountId((int) $validated['growth_circle_source_account_id']);
    SettingService::setGrowthCircleFoodPerCity((int) $validated['growth_circle_food_per_city']);
    SettingService::setGrowthCircleUraniumPerCity((int) $validated['growth_circle_uranium_per_city']);
    SettingService::setGrowthCircleDiscordChannelId($validated['growth_circle_discord_channel_id'] ?? '');

    $this->auditLogger->success(
        category: 'settings',
        action: 'growth_circles_updated',
        context: ['data' => $validated],
        message: 'Growth Circles settings updated.'
    );

    return redirect()->route('admin.settings')->with([
        'alert-message' => 'Growth Circles settings saved.',
        'alert-type' => 'success',
    ]);
}
```

- [ ] **Step 3: Add route for `updateGrowthCircles` in `routes/web.php`**

After the `admin.settings.auto-withdraw` route entry:

```php
Route::post('/settings/growth-circles', [SettingsController::class, 'updateGrowthCircles'])
    ->name('admin.settings.growth-circles');
```

- [ ] **Step 4: Add settings card to `resources/views/admin/settings.blade.php`**

Add the following card in the settings grid, after the Auto Withdraw card:

```blade
<div class="col-lg-6">
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Growth Circles</span>
            <span class="badge {{ $growthCirclesEnabled ? 'text-bg-success' : 'text-bg-secondary' }}">
                {{ $growthCirclesEnabled ? 'Enabled' : 'Disabled' }}
            </span>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Opt-in program that taxes members at 100% and automatically distributes food and uranium to support their growth.
            </p>
            <form method="POST" action="{{ route('admin.settings.growth-circles') }}">
                @csrf
                <div class="form-check form-switch mb-3">
                    <input type="hidden" name="growth_circles_enabled" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="growthCirclesEnabled"
                           name="growth_circles_enabled" value="1" @checked($growthCirclesEnabled)>
                    <label class="form-check-label" for="growthCirclesEnabled">Enable Growth Circles</label>
                </div>
                <div class="mb-3">
                    <label for="gcTaxId" class="form-label">P&W Tax Bracket ID (100%)</label>
                    <input type="number" class="form-control" id="gcTaxId"
                           name="growth_circle_tax_id" value="{{ $growthCircleTaxId }}" min="0">
                </div>
                <div class="mb-3">
                    <label for="gcFallbackTaxId" class="form-label">Fallback Tax Bracket ID</label>
                    <input type="number" class="form-control" id="gcFallbackTaxId"
                           name="growth_circle_fallback_tax_id" value="{{ $growthCircleFallbackTaxId }}" min="0">
                </div>
                <div class="mb-3">
                    <label for="gcSourceAccount" class="form-label">Source Account</label>
                    <select class="form-select" id="gcSourceAccount" name="growth_circle_source_account_id">
                        <option value="0">— Select account —</option>
                        @foreach ($sourceAccounts as $account)
                            <option value="{{ $account->id }}" @selected($growthCircleSourceAccountId === $account->id)>
                                {{ $account->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col">
                        <label for="gcFoodPerCity" class="form-label">Food per city</label>
                        <input type="number" class="form-control" id="gcFoodPerCity"
                               name="growth_circle_food_per_city" value="{{ $growthCircleFoodPerCity }}" min="0">
                    </div>
                    <div class="col">
                        <label for="gcUraniumPerCity" class="form-label">Uranium per city</label>
                        <input type="number" class="form-control" id="gcUraniumPerCity"
                               name="growth_circle_uranium_per_city" value="{{ $growthCircleUraniumPerCity }}" min="0">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="gcDiscordChannel" class="form-label">Abuse Alert Discord Channel ID</label>
                    <input type="text" class="form-control" id="gcDiscordChannel"
                           name="growth_circle_discord_channel_id" value="{{ $growthCircleDiscordChannelId }}">
                </div>
                <button class="btn btn-primary">Save Growth Circles Settings</button>
            </form>
        </div>
    </div>
</div>
```

- [ ] **Step 5: Format**

```bash
./vendor/bin/pint app/Http/Controllers/Admin/SettingsController.php
```

---

### Task 13: Create admin Growth Circles index view

**Files:**
- Create: `resources/views/admin/growth-circles/index.blade.php`
- Create: `resources/views/admin/growth-circles/distributions.blade.php`

- [ ] **Step 1: Create directory**

```bash
mkdir -p resources/views/admin/growth-circles
```

- [ ] **Step 2: Create `index.blade.php`**

```blade
@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <h3 class="mb-1">Growth Circles</h3>
                    <p class="text-secondary mb-0">Enrolled members, distribution status, and abuse flags.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            @if (session('alert-message'))
                <div class="alert alert-{{ session('alert-type') === 'success' ? 'success' : 'danger' }} alert-dismissible">
                    {{ session('alert-message') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <div class="card shadow-sm">
                <div class="card-header">Enrolled Nations</div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Nation</th>
                                <th>Cities</th>
                                <th>Enrolled</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($enrollments as $enrollment)
                                <tr class="{{ $enrollment->suspended ? 'table-warning' : '' }}">
                                    <td>{{ $enrollment->nation?->nation_name ?? '—' }}</td>
                                    <td>{{ $enrollment->nation?->num_cities ?? '—' }}</td>
                                    <td>{{ $enrollment->enrolled_at->diffForHumans() }}</td>
                                    <td>
                                        @if ($enrollment->suspended)
                                            <span class="badge text-bg-warning">Suspended</span>
                                            <small class="text-muted d-block">{{ $enrollment->suspended_reason }}</small>
                                        @else
                                            <span class="badge text-bg-success">Active</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <a href="{{ route('admin.growth-circles.distributions', $enrollment->nation) }}"
                                               class="btn btn-sm btn-outline-secondary">History</a>

                                            @can('manage-growth-circles')
                                                @if ($enrollment->suspended)
                                                    <form method="POST"
                                                          action="{{ route('admin.growth-circles.clear-suspension', $enrollment) }}">
                                                        @csrf
                                                        <button class="btn btn-sm btn-outline-success"
                                                                onclick="return confirm('Clear suspension for {{ $enrollment->nation?->nation_name }}?')">
                                                            Clear Suspension
                                                        </button>
                                                    </form>
                                                @endif

                                                <form method="POST"
                                                      action="{{ route('admin.growth-circles.remove', $enrollment->nation) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn btn-sm btn-outline-danger"
                                                            onclick="return confirm('Remove {{ $enrollment->nation?->nation_name }} from Growth Circles? Their previous tax bracket will be restored.')">
                                                        Remove
                                                    </button>
                                                </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No nations enrolled.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($enrollments->hasPages())
                    <div class="card-footer">{{ $enrollments->links() }}</div>
                @endif
            </div>
        </div>
    </div>
@endsection
```

- [ ] **Step 3: Create `distributions.blade.php`**

```blade
@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <h3 class="mb-1">Distribution History — {{ $nation->nation_name }}</h3>
                    <p class="text-secondary mb-0">Last 30 distribution cycles.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <a href="{{ route('admin.growth-circles.index') }}" class="btn btn-outline-secondary mb-3">← Back</a>

            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Cities</th>
                                <th>Food Before</th>
                                <th>Food Sent</th>
                                <th>Uranium Before</th>
                                <th>Uranium Sent</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($distributions as $dist)
                                <tr>
                                    <td>{{ $dist->created_at->diffForHumans() }}</td>
                                    <td>{{ $dist->city_count }}</td>
                                    <td>{{ number_format($dist->food_level_before) }}</td>
                                    <td>{{ number_format($dist->food_sent) }}</td>
                                    <td>{{ number_format($dist->uranium_level_before, 1) }}</td>
                                    <td>{{ number_format($dist->uranium_sent, 1) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No distribution records.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
```

---

### Task 14: Add enrollment card to user dashboard

**Files:**
- Modify: `resources/views/user/dashboard.blade.php`

- [ ] **Step 1: Add the Growth Circles card**

Open `resources/views/user/dashboard.blade.php`. Find the section that contains the existing feature cards (near the Direct Deposit card if it exists, or after the accounts section). Add:

```blade
@if (\App\Services\SettingService::isGrowthCirclesEnabled())
    <div class="card bg-base-100 shadow border border-base-300">
        <div class="card-body">
            <h3 class="card-title">Growth Circles</h3>

            @if ($growthCircleEnrollment)
                @if ($growthCircleEnrollment->suspended)
                    <p class="text-sm text-warning">
                        Your distributions are currently paused. Please contact an admin to resolve this.
                    </p>
                @else
                    <p class="text-sm text-base-content/70">
                        You are enrolled in Growth Circles. You are taxed at 100% and the alliance automatically
                        tops up your food and uranium every 2 hours.
                    </p>
                    <p class="text-xs text-base-content/50">Enrolled {{ $growthCircleEnrollment->enrolled_at->diffForHumans() }}</p>
                @endif
            @else
                <p class="text-sm text-base-content/70">
                    Join Growth Circles to have 100% of your taxes go to the alliance in exchange for automatic
                    food and uranium distributions every 2 hours.
                </p>
                <form method="POST" action="{{ route('growth-circles.enroll') }}">
                    @csrf
                    <button class="btn btn-primary btn-sm"
                            onclick="return confirm('Enroll in Growth Circles? You will be taxed at 100% and cannot unenroll yourself. Contact an admin to leave.')">
                        Enroll in Growth Circles
                    </button>
                </form>
            @endif
        </div>
    </div>
@endif
```

- [ ] **Step 2: Pass `$growthCircleEnrollment` from `NationDashboardService`**

The user dashboard is served by `UserController::dashboard()` (`app/Http/Controllers/UserController.php`, line ~294), which calls `NationDashboardService::getDashboardData($nation)` and merges its result into the view. Add `growthCircleEnrollment` to the return array in `NationDashboardService::getDashboardData()` (`app/Services/NationDashboardService.php`, in the `return [...]` block starting around line 64):

```php
'growthCircleEnrollment' => \App\Models\GrowthCircleEnrollment::where('nation_id', $nation->id)->first(),
```

Also add the import at the top of `NationDashboardService.php`:

```php
use App\Models\GrowthCircleEnrollment;
```

- [ ] **Step 3: Run Pint on modified files**

```bash
./vendor/bin/pint app/Services/NationDashboardService.php
```

---

### Task 15: Final commit

- [ ] **Step 1: Run full Pint pass**

```bash
./vendor/bin/pint
```

- [ ] **Step 2: Verify routes register**

```bash
php artisan route:list | grep -i growth
```

Expected: routes for `growth-circles.enroll`, `admin.growth-circles.index`, `admin.growth-circles.remove`, `admin.growth-circles.clear-suspension`, `admin.growth-circles.distributions`, `admin.settings.growth-circles`.

- [ ] **Step 3: Commit**

```bash
git add -p
git commit -m "$(cat <<'EOF'
Add Growth Circles program

Introduces opt-in 100% tax program with automatic food/uranium distributions,
abuse detection with auto-suspension, and admin management UI.

Post-deploy steps:
- Create a 100% tax bracket in P&W and enter its ID in Admin → Settings → Growth Circles
- Configure source account, per-city thresholds, and Discord alert channel
- Run: php artisan migrate

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Manual QA Checklist

After implementation, verify end-to-end:

1. **Settings page** — Visit Admin → Settings. Growth Circles card appears. Save settings (enable toggle, enter tax bracket IDs, select source account, set food/uranium per city, Discord channel ID). Confirm values persist after page reload.
2. **Permissions** — Create a role with `view-growth-circles` only. Confirm admin Growth Circles index is accessible but Remove/Clear Suspension buttons are absent. Add `manage-growth-circles`. Confirm buttons appear.
3. **Enrollment** — Log in as a verified member nation. Enable Growth Circles in settings. Dashboard card appears with Enroll button. Click enroll with confirmation. Confirm card switches to enrolled status. Confirm no unenroll button exists.
4. **Duplicate enrollment** — Try enrolling the same nation again (e.g., via direct POST). Confirm error is returned, no duplicate DB row.
5. **Admin remove** — In Admin → Growth Circles, click Remove on the test nation. Confirm enrollment record is deleted. Confirm dashboard card shows Enroll button again.
6. **Distribution command (dry run)** — Run `php artisan growth-circles:distribute`. Confirm it exits early if `growth_circles_enabled` is false. Enable it, confirm it runs without errors and logs output.
7. **Abuse detection** — Manually insert a `growth_circle_distributions` row with large `food_sent` and a nation with near-zero food. Trigger `GrowthCircleService::runAbuseDetection()` via Tinker. Confirm the enrollment is suspended and a Discord notification would be sent (check queue).
8. **Clear suspension** — In the admin Growth Circles screen, confirm a suspended nation shows the Clear Suspension button. Click it and confirm the suspension fields are cleared.
9. **Distribution history** — Click History on an enrolled nation. Confirm the distributions table renders.
