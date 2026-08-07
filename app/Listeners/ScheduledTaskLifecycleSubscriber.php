<?php

namespace App\Listeners;

use App\Services\Scheduling\ScheduledTaskLifecycleRecorder;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Events\Dispatcher;

class ScheduledTaskLifecycleSubscriber
{
    public function __construct(
        private readonly ScheduledTaskLifecycleRecorder $recorder,
    ) {}

    public function handleStarting(ScheduledTaskStarting $event): void
    {
        $this->recorder->recordStarting($event);
    }

    public function handleFinished(ScheduledTaskFinished $event): void
    {
        $this->recorder->recordFinished($event);
    }

    public function handleBackgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $this->recorder->recordBackgroundFinished($event);
    }

    public function handleSkipped(ScheduledTaskSkipped $event): void
    {
        $this->recorder->recordSkipped($event);
    }

    public function handleFailed(ScheduledTaskFailed $event): void
    {
        $this->recorder->recordFailed($event);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ScheduledTaskStarting::class => 'handleStarting',
            ScheduledTaskFinished::class => 'handleFinished',
            ScheduledBackgroundTaskFinished::class => 'handleBackgroundFinished',
            ScheduledTaskSkipped::class => 'handleSkipped',
            ScheduledTaskFailed::class => 'handleFailed',
        ];
    }
}
