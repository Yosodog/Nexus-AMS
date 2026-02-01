<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WarAidRequest;
use App\Services\AuditLogger;
use App\Services\PWHelperService;
use App\Services\SettingService;
use App\Services\WarAidService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WarAidController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return Factory|View|Application|object
     *
     * @throws AuthorizationException
     */
    public function index()
    {
        $this->authorize('view-war-aid');

        $pending = WarAidRequest::where('status', 'pending')->with('nation', 'account')->get();
        $history = WarAidRequest::whereIn('status', ['approved', 'denied'])->with('nation', 'account')->latest(
        )->paginate(25);
        $enabled = SettingService::isWarAidEnabled();

        return view('admin.defense.war-aid', compact('pending', 'history', 'enabled'));
    }

    /**
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function approve(Request $request, WarAidRequest $aidRequest, WarAidService $warAidService)
    {
        $this->authorize('manage-war-aid');

        $data = $request->validate(
            collect(PWHelperService::resources())->mapWithKeys(fn ($r) => [$r => ['nullable', 'integer', 'min:0']]
            )->toArray()
        );

        $warAidService->approveAidRequest($aidRequest, $data);

        return back()->with([
            'alert-message' => 'Aid request approved.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function deny(WarAidRequest $aidRequest, WarAidService $warAidService)
    {
        $this->authorize('manage-war-aid');

        $warAidService->denyAidRequest($aidRequest);

        return back()->with([
            'alert-message' => 'Aid request denied.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function toggle()
    {
        $this->authorize('manage-war-aid');

        $currently = SettingService::isWarAidEnabled();
        SettingService::setWarAidEnabled(! $currently);

        $this->auditLogger->success(
            category: 'settings',
            action: 'war_aid_toggle',
            context: [
                'changes' => [
                    'war_aid_enabled' => [
                        'from' => $currently,
                        'to' => ! $currently,
                    ],
                ],
            ],
            message: 'War aid toggle updated.'
        );

        return back()->with([
            'alert-message' => 'War aid has been '.($currently ? 'disabled' : 'enabled').'.',
            'alert-type' => 'info',
        ]);
    }
}
