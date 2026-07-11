<?php

namespace App\Services\BlockadeRelief;

use App\Models\BlockadeReliefRequest;
use App\Models\Nation;
use App\Services\Discord\PrivateNotificationService;
use Illuminate\Support\Collection;

class BlockadeReliefNotificationService
{
    public function __construct(private readonly PrivateNotificationService $privateNotifications) {}

    /** @param Collection<int, Nation> $recipients */
    public function enqueue(BlockadeReliefRequest $request, string $eventType, Collection $recipients): void
    {
        $request->loadMissing(['requester.user.discordAccounts', 'blockadingNation', 'claimer.user.discordAccounts']);
        $recipients
            ->push($request->requester)
            ->when($request->claimer !== null, fn (Collection $items) => $items->push($request->claimer))
            ->filter()
            ->unique('id')
            ->take(10)
            ->each(function (Nation $nation) use ($request, $eventType): void {
                $this->privateNotifications->enqueueForNation(
                    $nation,
                    'blockade_relief',
                    'blockade_relief_'.$eventType,
                    sprintf(
                        'blockade-relief:%d:%s:%d:%s',
                        $request->id,
                        $eventType,
                        $nation->id,
                        $request->updated_at->format('Uu'),
                    ),
                    [
                        'type' => 'blockade_relief_request',
                        'id' => $request->id,
                        'label' => 'Request #'.$request->id,
                    ],
                    '/defense/blockade-relief',
                    [
                        'status' => $request->status->value,
                        'event' => str_replace('_', ' ', $eventType),
                    ],
                );
            });
    }
}
