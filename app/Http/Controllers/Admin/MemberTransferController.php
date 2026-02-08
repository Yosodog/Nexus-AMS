<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MemberTransfer;
use App\Services\MemberTransferService;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class MemberTransferController extends Controller
{
    public function __construct(private readonly MemberTransferService $memberTransferService) {}

    public function cancel(MemberTransfer $memberTransfer): RedirectResponse
    {
        try {
            $this->memberTransferService->cancelTransfer(Auth::user(), $memberTransfer, true);

            return redirect()->back()->with([
                'alert-message' => 'Transfer canceled and refunded.',
                'alert-type' => 'info',
            ]);
        } catch (Exception $e) {
            Log::error('Error canceling member transfer (admin): '.$e->getMessage());

            return redirect()->back()->with([
                'alert-message' => 'Unable to cancel the transfer right now.',
                'alert-type' => 'error',
            ]);
        }
    }
}
