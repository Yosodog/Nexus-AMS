<?php

namespace App\Logs;

use Opcodes\LogViewer\LogLevels\LevelInterface;
use Opcodes\LogViewer\LogLevels\LevelClass;

class SubLogLevel implements LevelInterface
{
    public function __construct(public readonly string $value)
    {
    }

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
            'startup',
            'shutdown',
            'received-channel',
            'subscribed',
            'update-sent',
            'pusher-connected',
            'config',
            'info',
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
            'startup',
            'subscribed',
            'update-sent',
            'pusher-connected',
            'create'          => LevelClass::success(),

            'received-channel',
            'info'            => LevelClass::info(),

            'config'          => LevelClass::warning(),

            'delete',
            'shutdown'        => LevelClass::danger(),

            default           => LevelClass::none(),
        };
    }
}
