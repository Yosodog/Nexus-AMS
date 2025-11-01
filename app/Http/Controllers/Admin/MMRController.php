<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MMRSetting;
use App\Models\MMRTier;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\MMRService;
use App\Services\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MMRController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display the MMR index page.
     *
     * Authorizes the user to view the MMR page, retrieves MMR tiers
     * ordered by city count, fetches all nations with their latest
     * sign-in details, and evaluates each nation's MMR based on the
     * associated sign-in data.
     *
     * @param  MMRService  $service  The service used to evaluate nations' MMR.
     * @return View The
     *
     * @throws AuthorizationException
     */
    public function index(MMRService $service, AllianceMembershipService $membershipService): View
    {
        $this->authorize('view-mmr');

        $tiers = MMRTier::orderBy('city_count')->get();

        $nations = Nation::with('latestSignIn')
            ->whereIn('alliance_id', $membershipService->getAllianceIds())
            ->get()
            ->filter(fn ($nation) => $nation->latestSignIn); // filter out those missing sign-ins

        $evaluations = $nations->mapWithKeys(function ($nation) use ($service) {
            return [$nation->id => $service->evaluate($nation, $nation->latestSignIn)];
        });

        return view('admin.mmr.index', compact('tiers', 'nations', 'evaluations'));
    }

    /**
     * @throws AuthorizationException
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-mmr');

        $validated = $request->validate([
            'city_count' => 'required|integer|min:1|unique:mmr_tiers,city_count',
        ]);

        $tier = MMRTier::create(
            array_merge(
                ['city_count' => $validated['city_count']],
                array_fill_keys([
                    'money',
                    'steel',
                    'aluminum',
                    'munitions',
                    'uranium',
                    'food',
                    'gasoline',
                    'barracks',
                    'factories',
                    'hangars',
                    'drydocks',
                    'missiles',
                    'nukes',
                    'spies',
                ], 0)
            )
        );

        return redirect()->route('admin.mmr.index')->with([
            'alert-message' => "Tier for {$validated['city_count']} cities created.",
            'alert-type' => 'success',
        ]);
    }

    /**
     * Deletes the specified MMR tier after ensuring authorization,
     * while protecting Tier 0 from being deleted. Provides feedback on
     * the operation's outcome.
     *
     * @return RedirectResponse Redirect response to the MMR index route with an alert message.
     *
     * @throws AuthorizationException
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->authorize('manage-mmr');

        $tier = MMRTier::find((int) $request->input('tier_id'));

        if (! $tier || $tier->city_count === 0) {
            return redirect()->route('admin.mmr.index')->with([
                'alert-message' => 'Invalid or protected tier.',
                'alert-type' => 'error',
            ]);
        }

        $tier->delete();

        return redirect()->route('admin.mmr.index')->with([
            'alert-message' => 'Tier deleted.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * Updates all tiers' fields in bulk, skipping Tier 0 and any missing tiers.
     *
     * Authorizes the user, validates incoming data, and iterates through each
     * tier to update its fields. Each field is validated to ensure it meets
     * the required criteria before updating the tier.
     *
     * @param  Request  $request  The incoming HTTP request containing the data to update.
     * @return RedirectResponse A redirect response indicating the result of the operation.
     *
     * @throws AuthorizationException If the user is not authorized to manage MMR.
     */
    public function updateAll(Request $request): RedirectResponse
    {
        $this->authorize('manage-mmr');
        $fields = [
            'money',
            'steel',
            'aluminum',
            'munitions',
            'uranium',
            'food',
            'gasoline',
            'barracks',
            'factories',
            'hangars',
            'drydocks',
            'missiles',
            'nukes',
            'spies',
        ];
        $tiers = $request->input('tiers', []);
        $errors = [];

        foreach ($tiers as $id => $data) {
            $tier = MMRTier::find($id);

            $update = [];

            foreach ($fields as $field) {
                $value = $data[$field] ?? 0;
                if (! is_numeric($value) || $value < 0) {
                    $errors["tiers.$id.$field"] = 'Must be a non-negative number.';
                } else {
                    $update[$field] = max(0, (int) $value);
                }
            }

            if (empty($errors)) {
                $tier->update($update);
            }
        }

        return redirect()->route('admin.mmr.index')->with([
            'alert-message' => 'All tiers updated.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function updateAssistantSettings(Request $request): RedirectResponse
    {
        $this->authorize('manage-mmr');

        SettingService::setMMRAssistantEnabled($request->input('enabled', false));

        foreach ($request->input('resources', []) as $resource => $data) {
            MMRSetting::updateOrCreate(
                ['resource' => $resource],
                [
                    'enabled' => isset($data['enabled']),
                    'surcharge_pct' => floatval($data['surcharge_pct'] ?? 0),
                ]
            );
        }

        return redirect()->route('admin.mmr.index')->with([
            'alert-message' => 'MMR Assistant settings updated.',
            'alert-type' => 'success',
        ]);
    }
}
