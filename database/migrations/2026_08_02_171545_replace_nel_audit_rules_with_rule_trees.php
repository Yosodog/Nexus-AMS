<?php

use App\Enums\AuditTargetType;
use App\Services\Audit\AuditRuleDefinitionService;
use Database\Migrations\Support\AuditRuleMigration\LegacyNelToAuditRuleTreeConverter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/support/AuditRuleMigration/bootstrap.php';

return new class extends Migration
{
    private const REBUILD_MESSAGE = 'This imported rule could not be converted safely. Rebuild it with the guided rule editor.';

    public function up(): void
    {
        Schema::table('audit_rules', function (Blueprint $table): void {
            $table->json('definition')->nullable()->after('priority');
            $table->unsignedInteger('revision')->default(1)->after('definition');
            $table->text('remediation_guidance')->nullable()->after('description');
            $table->text('admin_notes')->nullable()->after('remediation_guidance');
            $table->string('last_evaluation_status', 32)->default('never_run')->after('enabled');
            $table->timestamp('last_evaluated_at')->nullable()->after('last_evaluation_status');
            $table->unsignedInteger('last_match_count')->nullable()->after('last_evaluated_at');
            $table->text('last_evaluation_error')->nullable()->after('last_match_count');
            $table->unsignedInteger('last_evaluation_duration_ms')->nullable()->after('last_evaluation_error');
            $table->index(['last_evaluation_status', 'enabled'], 'audit_rules_health_enabled_idx');
        });

        Schema::table('audit_results', function (Blueprint $table): void {
            $table->unsignedInteger('rule_revision')->default(1)->after('audit_rule_id');
            $table->index(['audit_rule_id', 'rule_revision'], 'audit_results_rule_revision_idx');
        });

        $converter = new LegacyNelToAuditRuleTreeConverter;

        DB::table('audit_rules')
            ->orderBy('id')
            ->chunkById(100, function ($rules) use ($converter): void {
                foreach ($rules as $rule) {
                    $conversion = $converter->convert((string) $rule->expression, (string) $rule->target_type);
                    $definition = $conversion->definition;
                    $validationErrors = [];
                    $targetType = AuditTargetType::tryFrom((string) $rule->target_type);

                    if ($conversion->succeeded && $definition !== null && $targetType !== null) {
                        $inspection = app(AuditRuleDefinitionService::class)->inspect($definition, $targetType);
                        $definition = $inspection['normalized'];
                        $validationErrors = $inspection['errors'];
                    }

                    if ($conversion->succeeded && $definition !== null && $validationErrors === []) {
                        DB::table('audit_rules')->where('id', $rule->id)->update([
                            'definition' => json_encode($definition, JSON_THROW_ON_ERROR),
                            'revision' => 1,
                            'last_evaluation_status' => 'never_run',
                            'last_match_count' => DB::table('audit_results')->where('audit_rule_id', $rule->id)->count(),
                            'last_evaluation_error' => null,
                        ]);

                        DB::table('audit_results')->where('audit_rule_id', $rule->id)->update([
                            'rule_revision' => 1,
                        ]);

                        continue;
                    }

                    $reason = $conversion->unsupported ?? [
                        'code' => 'converted_definition_invalid',
                        'message' => 'The converted definition did not pass typed rule validation.',
                        'context' => ['validation_errors' => implode(' ', $validationErrors)],
                    ];

                    $this->disableFailedRule($rule, $reason);
                }
            }, 'id');

        $invalidEnabledRuleIds = DB::table('audit_rules')
            ->where('enabled', true)
            ->get(['id', 'target_type', 'definition'])
            ->filter(function (object $rule): bool {
                $targetType = AuditTargetType::tryFrom((string) $rule->target_type);
                $definition = json_decode((string) $rule->definition, true);

                if ($targetType === null || ! is_array($definition)) {
                    return true;
                }

                $inspection = app(AuditRuleDefinitionService::class)->inspect($definition, $targetType);

                return $inspection['errors'] !== []
                    || $inspection['normalized'] === null
                    || ! app(AuditRuleDefinitionService::class)->hasCriteria($inspection['normalized']);
            })
            ->pluck('id')
            ->all();

        if ($invalidEnabledRuleIds !== []) {
            throw new RuntimeException('NEL migration left invalid enabled audit rules: '.implode(', ', $invalidEnabledRuleIds));
        }

        Schema::table('audit_rules', function (Blueprint $table): void {
            $table->dropColumn('expression');
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'This audit-rule migration is irreversible. Restore the pre-deployment database backup and application release.',
        );
    }

    /**
     * @param  array{code: string, message: string, context: array<string, mixed>}  $reason
     */
    private function disableFailedRule(object $rule, array $reason): void
    {
        Log::warning('Legacy audit rule conversion failed', [
            'event' => 'audit_rule_migration_failed',
            'rule_id' => (int) $rule->id,
            'rule_name' => (string) $rule->name,
            'target_type' => (string) $rule->target_type,
            'original_expression' => (string) $rule->expression,
            'reason_code' => $reason['code'],
            'reason' => $reason['message'],
            'context' => $reason['context'],
        ]);

        $results = DB::table('audit_results')
            ->where('audit_rule_id', $rule->id)
            ->orderBy('id')
            ->get();
        $occurredAt = now();

        if (Schema::hasTable('audit_result_events') && $results->isNotEmpty()) {
            DB::table('audit_result_events')->insert($results->map(fn (object $result): array => [
                'audit_result_id' => $result->id,
                'audit_rule_id' => $rule->id,
                'target_type' => $result->target_type,
                'target_key' => $result->target_key,
                'nation_id' => $result->nation_id,
                'city_id' => $result->city_id,
                'actor_user_id' => null,
                'event_type' => 'migration_disabled',
                'metadata' => json_encode([
                    'reason' => 'Rule needs to be rebuilt after migration.',
                    'rule_snapshot' => [
                        'name' => $rule->name,
                        'priority' => $rule->priority,
                        'revision' => 1,
                        'summary' => null,
                    ],
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ])->all());
        }

        DB::table('audit_results')->where('audit_rule_id', $rule->id)->delete();
        DB::table('audit_rules')->where('id', $rule->id)->update([
            'definition' => null,
            'revision' => 1,
            'enabled' => false,
            'last_evaluation_status' => 'migration_failed',
            'last_evaluation_error' => self::REBUILD_MESSAGE,
            'last_match_count' => 0,
        ]);
    }
};
