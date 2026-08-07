<?php

namespace App\Services\Scheduling;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;

class ScheduledTaskIdentifier
{
    public function for(Event $task): string
    {
        if ($task instanceof CallbackEvent) {
            return $this->forCallback($task);
        }

        $normalizedCommand = Event::normalizeCommand((string) $task->command);

        if (preg_match(
            '/(?:^|\s)[\'\"]?artisan[\'\"]?\s+[\'\"]?(?<command>[A-Za-z0-9][A-Za-z0-9:_-]*)/',
            $normalizedCommand,
            $matches,
        ) === 1) {
            return 'artisan:'.strtolower($matches['command']);
        }

        return 'shell:'.$this->mutexHash($task);
    }

    public function mutexHash(Event $task): string
    {
        return hash('sha256', (string) $task->mutexName());
    }

    private function forCallback(CallbackEvent $task): string
    {
        $description = trim((string) $task->description);

        if ($description !== '' && preg_match(
            '/^\\\\?[_A-Za-z][_A-Za-z0-9]*(?:\\\\[_A-Za-z][_A-Za-z0-9]*)+$/',
            $description,
        ) === 1) {
            return 'job:'.str_replace('\\', '.', ltrim($description, '\\'));
        }

        $source = $description !== '' ? $description : $task->mutexName();

        return 'callback:'.hash('sha256', $source);
    }
}
