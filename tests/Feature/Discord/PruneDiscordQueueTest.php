<?php

namespace Tests\Feature\Discord;

use App\Enums\DiscordQueueStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneDiscordQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prunes_only_old_terminal_rows(): void
    {
        config()->set('nexus.discord_queue.retention_days', 30);
        config()->set('nexus.discord_queue.prune_batch_size', 2);

        $old = now()->subDays(45);
        $recent = now()->subDays(5);

        $oldComplete = [$this->row(DiscordQueueStatus::Complete, $old), $this->row(DiscordQueueStatus::Complete, $old), $this->row(DiscordQueueStatus::Complete, $old)];
        $oldFailed = $this->row(DiscordQueueStatus::Failed, $old);
        $keep = [
            $this->row(DiscordQueueStatus::Pending, $old),
            $this->row(DiscordQueueStatus::Processing, $old),
            $this->row(DiscordQueueStatus::Complete, $recent),
            $this->row(DiscordQueueStatus::Failed, $recent),
        ];

        $this->artisan('discord-queue:prune', ['--pretend' => true])->assertSuccessful();
        $this->assertDatabaseCount('discord_queue', 8);

        $this->artisan('discord-queue:prune')->assertSuccessful();

        foreach ([...$oldComplete, $oldFailed] as $id) {
            $this->assertDatabaseMissing('discord_queue', ['id' => $id]);
        }
        foreach ($keep as $id) {
            $this->assertDatabaseHas('discord_queue', ['id' => $id]);
        }
    }

    private function row(DiscordQueueStatus $status, \DateTimeInterface $updatedAt): string
    {
        $id = (string) Str::uuid();

        DB::table('discord_queue')->insert([
            'id' => $id,
            'action' => 'PRIVATE_NOTIFICATION',
            'payload' => '{}',
            'status' => $status->value,
            'attempts' => 0,
            'priority' => 50,
            'lane' => 'alerts',
            'dedupe_scope' => 'test-scope',
            'dedupe_key' => $id,
            'available_at' => $updatedAt,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);

        return $id;
    }
}
