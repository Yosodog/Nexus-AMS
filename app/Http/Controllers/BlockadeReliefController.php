<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBlockadeReliefRequest;
use App\Models\BlockadeReliefRequest;
use App\Models\User;
use App\Services\BlockadeRelief\BlockadeReliefService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class BlockadeReliefController extends Controller
{
    public function index(BlockadeReliefService $service): View
    {
        $user = $this->user();

        return view('defense.blockade-relief', [
            'blockadedWars' => $service->blockadedWarsFor($user),
            'requests' => $service->requestsFor($user),
            'availableRequests' => $service->availableFor($user),
        ]);
    }

    public function store(StoreBlockadeReliefRequest $request, BlockadeReliefService $service): RedirectResponse
    {
        $data = $request->validated();
        $service->create(
            $this->user(),
            (int) $data['war_id'],
            $data['note'] ?? null,
            (int) ($data['deadline_hours'] ?? 6),
        );

        return redirect()->route('defense.blockade-relief')->with([
            'alert-message' => 'Your blockade relief request has been opened.',
            'alert-type' => 'success',
        ]);
    }

    public function claim(BlockadeReliefRequest $blockadeReliefRequest, BlockadeReliefService $service): RedirectResponse
    {
        $service->claim($blockadeReliefRequest, $this->user());

        return redirect()->route('defense.blockade-relief')->with([
            'alert-message' => 'You claimed the blockade relief request. Recheck the war before acting.',
            'alert-type' => 'success',
        ]);
    }

    public function cancel(BlockadeReliefRequest $blockadeReliefRequest, BlockadeReliefService $service): RedirectResponse
    {
        $service->cancel($blockadeReliefRequest, $this->user());

        return redirect()->route('defense.blockade-relief')->with([
            'alert-message' => 'The blockade relief request was cancelled.',
            'alert-type' => 'success',
        ]);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
