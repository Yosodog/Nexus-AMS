<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RevokeMemberInactivityExceptionRequest;
use App\Http\Requests\Admin\StoreMemberInactivityExceptionRequest;
use App\Http\Requests\Admin\UpdateMemberInactivityExceptionRequest;
use App\Models\MemberInactivityException;
use App\Models\Nation;
use App\Models\User;
use App\Services\MemberInactivityExceptionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

class MemberInactivityExceptionController extends Controller
{
    use AuthorizesRequests;

    public function store(
        StoreMemberInactivityExceptionRequest $request,
        Nation $nation,
        MemberInactivityExceptionService $service,
    ): RedirectResponse {
        $this->authorize('create', MemberInactivityException::class);

        /** @var User $approver */
        $approver = $request->user();
        $service->create($nation, $approver, $request->validated());

        return $this->redirectToMember($nation, 'Member inactivity exception approved.');
    }

    public function update(
        UpdateMemberInactivityExceptionRequest $request,
        Nation $nation,
        MemberInactivityException $memberInactivityException,
        MemberInactivityExceptionService $service,
    ): RedirectResponse {
        $this->authorize('update', $memberInactivityException);

        /** @var User $reviewer */
        $reviewer = $request->user();
        $service->update($nation, $memberInactivityException, $reviewer, $request->validated());

        return $this->redirectToMember($nation, 'Member inactivity exception updated.');
    }

    public function destroy(
        RevokeMemberInactivityExceptionRequest $request,
        Nation $nation,
        MemberInactivityException $memberInactivityException,
        MemberInactivityExceptionService $service,
    ): RedirectResponse {
        $this->authorize('delete', $memberInactivityException);

        /** @var User $reviewer */
        $reviewer = $request->user();
        $service->revoke(
            $nation,
            $memberInactivityException,
            $reviewer,
            (string) $request->validated('revocation_reason'),
        );

        return $this->redirectToMember($nation, 'Member inactivity exception revoked.');
    }

    private function redirectToMember(Nation $nation, string $message): RedirectResponse
    {
        return redirect()
            ->to(route('admin.members.show', $nation).'#inactivity-exceptions')
            ->with([
                'alert-message' => $message,
                'alert-type' => 'success',
            ]);
    }
}
