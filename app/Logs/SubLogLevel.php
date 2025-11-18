<?php

namespace App\Logs;

use Opcodes\LogViewer\LogLevels\LevelClass;
use Opcodes\LogViewer\LogLevels\LevelInterface;

class SubLogLevel implements LevelInterface
{
    public function __construct(public readonly string $value) {}

    /**
     * Correct signature required by LevelInterface
     */
    public static function from(?string $value = null): LevelInterface
    {
        return new static($value ?? 'info');
    }

    /**
     * Must return ALL possible level values we generate.
     */
    public static function caseValues(): array
    {
        return [
            'info',
            'warn',
            'error',
            'startup',
            'shutdown',
            'received-channel',
            'subscribed',
            'update-sent',
            'pusher-connected',
            'config',
            'create',
            'delete',
        ];
    }

    /**
     * Friendly name in UI
     */
    public function getName(): string
    {
        return ucfirst(str_replace('-', ' ', $this->value));
    }

    /**
     * Colors in UI
     */
    public function getClass(): LevelClass
    {
        return match ($this->value) {
            'error' => LevelClass::danger(),
            'warn' => LevelClass::warning(),
            'info' => LevelClass::info(),

            'startup',
            'subscribed',
            'update-sent',
            'pusher-connected',
            'create' => LevelClass::success(),

            'delete',
            'shutdown' => LevelClass::danger(),

            default => LevelClass::none(),
        };
    }
}
