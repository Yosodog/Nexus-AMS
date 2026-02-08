<?php

namespace App\Http\Controllers;

use App\Exceptions\UserErrorException;
use App\Http\Requests\MemberTransferSearchRequest;
use App\Models\MemberTransfer;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\MemberTransferService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class MemberTransferController extends Controller
{
    public function __construct(private readonly MemberTransferService $memberTransferService) {}

    public function accept(MemberTransfer $memberTransfer): RedirectResponse
    {
        try {
            $this->memberTransferService->acceptTransfer(Auth::user(), $memberTransfer);

            return redirect()->back()->with([
                'alert-message' => 'Transfer accepted and applied to your account.',
                'alert-type' => 'success',
            ]);
        } catch (UserErrorException $e) {
            return redirect()->back()->withErrors($e->getMessage())->with('alert-type', 'error');
        } catch (Exception $e) {
            Log::error('Error accepting member transfer: '.$e->getMessage());

            return redirect()->back()->with([
                'alert-message' => 'Unable to accept the transfer right now.',
                'alert-type' => 'error',
            ]);
        }
    }

    public function decline(MemberTransfer $memberTransfer): RedirectResponse
    {
        try {
            $this->memberTransferService->declineTransfer(Auth::user(), $memberTransfer);

            return redirect()->back()->with([
                'alert-message' => 'Transfer declined and refunded to the sender.',
                'alert-type' => 'info',
            ]);
        } catch (UserErrorException $e) {
            return redirect()->back()->withErrors($e->getMessage())->with('alert-type', 'error');
        } catch (Exception $e) {
            Log::error('Error declining member transfer: '.$e->getMessage());

            return redirect()->back()->with([
                'alert-message' => 'Unable to decline the transfer right now.',
                'alert-type' => 'error',
            ]);
        }
    }

    public function cancel(MemberTransfer $memberTransfer): RedirectResponse
    {
        try {
            $this->memberTransferService->cancelTransfer(Auth::user(), $memberTransfer);

            return redirect()->back()->with([
                'alert-message' => 'Transfer canceled and refunded to your account.',
                'alert-type' => 'info',
            ]);
        } catch (UserErrorException $e) {
            return redirect()->back()->withErrors($e->getMessage())->with('alert-type', 'error');
        } catch (Exception $e) {
            Log::error('Error canceling member transfer: '.$e->getMessage());

            return redirect()->back()->with([
                'alert-message' => 'Unable to cancel the transfer right now.',
                'alert-type' => 'error',
            ]);
        }
    }

    public function search(MemberTransferSearchRequest $request, AllianceMembershipService $membershipService): JsonResponse
    {
        $validated = $request->validated();
        $query = trim($validated['q']);
        $limit = (int) ($validated['limit'] ?? 10);
        $allianceIds = $membershipService->getAllianceIds();

        $results = Nation::query()
            ->whereIn('alliance_id', $allianceIds)
            ->where(function ($builder) use ($query) {
                $builder->where('nation_name', 'like', "%{$query}%")
                    ->orWhere('leader_name', 'like', "%{$query}%")
                    ->orWhere('id', $query);
            })
            ->with(['accounts' => function ($builder) {
                $builder->where('frozen', false)
                    ->orderBy('name');
            }])
            ->orderBy('nation_name')
            ->limit($limit)
            ->get()
            ->map(function (Nation $nation) {
                return [
                    'nation_id' => $nation->id,
                    'nation_name' => $nation->nation_name,
                    'leader_name' => $nation->leader_name,
                    'accounts' => $nation->accounts->map(fn ($account) => [
                        'id' => $account->id,
                        'name' => $account->name,
                    ])->values(),
                ];
            })
            ->values();

        return response()->json([
            'results' => $results,
        ]);
    }
}
