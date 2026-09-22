<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SystemComponent;
use App\Exceptions\UpdaterException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ComponentOperationRequest;
use App\Http\Requests\Admin\SystemMutationRequest;
use App\Services\SystemManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class SoftwareUpdatesController extends Controller
{
    public function __construct(private readonly SystemManagementService $systemManagement) {}

    public function index(Request $request): View
    {
        $this->authorizeRead($request);

        return view('admin.settings.software', [
            'snapshot' => $this->systemManagement->snapshot(),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $this->authorizeRead($request);

        return response()->json($this->systemManagement->snapshot(refreshUpdates: false));
    }

    public function operation(Request $request, string $operation): JsonResponse
    {
        $this->authorizeRead($request);

        try {
            return response()->json($this->systemManagement->operationStatus($operation));
        } catch (UpdaterException $exception) {
            return $this->updaterJsonError($exception);
        }
    }

    public function update(SystemMutationRequest $request): Response
    {
        return $this->start(
            $request,
            fn (): array => $this->systemManagement->startUpdate($request->user()),
            'Nexus update started.',
        );
    }

    public function rollback(SystemMutationRequest $request): Response
    {
        return $this->start(
            $request,
            fn (): array => $this->systemManagement->rollback($request->user()),
            'Nexus code rollback started.',
        );
    }

    public function cleanup(SystemMutationRequest $request): Response
    {
        return $this->start(
            $request,
            fn (): array => $this->systemManagement->cleanup($request->user()),
            'Nexus cleanup started.',
        );
    }

    public function componentOperation(
        ComponentOperationRequest $request,
        string $component,
        string $action,
    ): Response {
        $componentId = SystemComponent::tryFrom($component);
        abort_unless($componentId !== null, 404);

        return $this->start(
            $request,
            fn (): array => $this->systemManagement->componentOperation(
                actor: $request->user(),
                component: $componentId,
                action: $action,
                configuration: $request->validated('configuration', []),
            ),
            'Nexus component operation started.',
        );
    }

    /** @param callable(): array<string, mixed> $submit */
    private function start(Request $request, callable $submit, string $message): Response
    {
        try {
            $operation = $submit();

            if ($request->expectsJson()) {
                return response()->json($operation, Response::HTTP_ACCEPTED);
            }

            return redirect()
                ->route('admin.settings.software')
                ->with('alert-message', $message)
                ->with('alert-type', 'success')
                ->with('system-operation-id', $operation['id']);
        } catch (UpdaterException $exception) {
            if ($request->expectsJson()) {
                return $this->updaterJsonError($exception);
            }

            return redirect()->back()
                ->with('alert-message', $exception->getMessage())
                ->with('alert-type', 'error');
        }
    }

    private function authorizeRead(Request $request): void
    {
        abort_unless($request->user()?->is_admin && ! $request->user()->disabled, 403);
        $this->authorize('view-diagnostic-info');
    }

    private function updaterJsonError(UpdaterException $exception): JsonResponse
    {
        $status = $exception->errorCode === 'updater_unavailable'
            ? Response::HTTP_SERVICE_UNAVAILABLE
            : Response::HTTP_UNPROCESSABLE_ENTITY;

        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->errorCode,
        ], $status);
    }
}
