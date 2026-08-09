<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Federation\Enums\ImportState;
use App\Domain\Federation\Services\FederatedWarPlanImporter;
use App\Domain\Federation\Services\FederationHoldService;
use App\Domain\Federation\Services\FederationReceivedWarPlanService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FederationHoldResolutionRequest;
use App\Http\Requests\Admin\FederationReviewRequest;
use App\Models\FederationReceivedVersion;
use App\Models\MilcomOperation;
use Illuminate\Http\RedirectResponse;

class FederationReceivedPlanController extends Controller
{
    public function accept(
        FederationReviewRequest $request,
        FederationReceivedVersion $version,
        FederationReceivedWarPlanService $receivedPlans,
    ): RedirectResponse {
        $receivedPlans->accept($version, (int) $request->user()->id);

        return $this->back('Plan accepted. Automatic local draft import was queued.');
    }

    public function reject(
        FederationReviewRequest $request,
        FederationReceivedVersion $version,
        FederationReceivedWarPlanService $receivedPlans,
    ): RedirectResponse {
        $receivedPlans->reject($version, (int) $request->user()->id);

        return $this->back('Plan rejected. Decrypted payload purged and disposition queued.');
    }

    public function retryImport(
        FederationReviewRequest $request,
        FederationReceivedVersion $version,
        FederatedWarPlanImporter $importer,
    ): RedirectResponse {
        if ($version->import_state !== ImportState::BlockedMissingTargets
            && $version->import_state !== ImportState::Failed) {
            abort(409, 'This import is not retryable.');
        }

        $importer->import($version);

        return $this->back('Federated plan import retried against current nation data.');
    }

    public function detach(
        FederationHoldResolutionRequest $request,
        MilcomOperation $operation,
        FederationHoldService $holds,
    ): RedirectResponse {
        $holds->continueIndependently(
            $operation,
            $request->validated('reason'),
            (int) $request->user()->id,
        );

        return $this->back('Local operation detached permanently from its remote source.');
    }

    public function retire(
        FederationHoldResolutionRequest $request,
        MilcomOperation $operation,
        FederationHoldService $holds,
    ): RedirectResponse {
        $holds->retire(
            $operation,
            $request->validated('reason'),
            (int) $request->user()->id,
        );

        return $this->back('Local operation retired through the existing completion and archive lifecycle.');
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('admin.federation.index')->with([
            'alert-message' => $message,
            'alert-type' => 'success',
        ]);
    }
}
