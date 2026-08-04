<?php

namespace App\Http\Controllers\API\Milcom;

use App\Http\Controllers\Controller;
use App\Http\Requests\Milcom\SearchAlliancesRequest;
use App\Models\MilcomIncident;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Models\MilcomRecommendationRun;
use App\Services\Milcom\MilcomQueryService;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReadController extends Controller
{
    public function __construct(private readonly MilcomQueryService $queries) {}

    public function dashboard(): JsonResponse
    {
        $this->authorize('manage-war-room');

        return $this->respond($this->queries->dashboard());
    }

    public function alliances(SearchAlliancesRequest $request): JsonResponse
    {
        $this->authorize('manage-war-room');

        return $this->respond([
            'alliances' => $this->queries->searchAlliances(
                (string) $request->validated('q', ''),
                $request->validated('ids', []),
                (int) $request->validated('limit', 12),
            ),
        ]);
    }

    public function operations(Request $request): JsonResponse
    {
        $this->authorize('manage-war-room');

        return $this->paginated(
            'operations',
            $this->queries->operationsCursor($request->only(['type', 'status', 'search', 'limit']))
        );
    }

    public function operation(MilcomOperation $operation): JsonResponse
    {
        $this->authorize('manage-war-room');

        return $this->respond($this->queries->operationDetail($operation));
    }

    public function objectives(Request $request, MilcomOperation $operation): JsonResponse
    {
        $this->authorize('manage-war-room');

        return $this->paginated(
            'objectives',
            $this->queries->objectivesCursor(
                $operation,
                $request->only(['filter', 'tier', 'search', 'limit'])
            )
        );
    }

    public function assignments(Request $request, MilcomOperation $operation): JsonResponse
    {
        $this->authorize('manage-war-room');

        return $this->paginated(
            'assignments',
            $this->queries->assignmentsCursor($operation, $request->only(['status', 'limit']))
        );
    }

    public function objective(MilcomObjective $objective): JsonResponse
    {
        $this->authorize('manage-war-room');

        return $this->respond($this->queries->objectiveDetail($objective));
    }

    public function incidents(Request $request): JsonResponse
    {
        $this->authorize('manage-war-room');

        return $this->paginated(
            'incidents',
            $this->queries->incidentsCursor($request->only(['filter', 'search', 'limit']))
        );
    }

    public function incident(MilcomIncident $incident): JsonResponse
    {
        $this->authorize('manage-war-room');

        return $this->respond($this->queries->incidentDetail($incident));
    }

    public function recommendationRun(MilcomRecommendationRun $run): JsonResponse
    {
        $this->authorize('manage-war-room');

        return $this->respond(['recommendation_run' => $this->queries->recommendationProgress($run)]);
    }

    public function events(Request $request): JsonResponse
    {
        $this->authorize('manage-war-room');

        return $this->respond([
            'events' => $this->queries->events($request->only([
                'operation_id',
                'objective_id',
                'incident_id',
                'after_id',
                'limit',
            ]))->all(),
        ]);
    }

    public function legacy(string $type, int $id): JsonResponse
    {
        $this->authorize('manage-war-room');
        abort_unless(in_array($type, ['plans', 'counters'], true), 404);

        return $this->respond($this->queries->legacyDetail($type, $id));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => ['generated_at' => now()->toIso8601String()],
            'links' => [],
        ], $status);
    }

    private function paginated(string $key, CursorPaginator $paginator): JsonResponse
    {
        return response()->json([
            'data' => [$key => $paginator->items()],
            'meta' => [
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'previous_cursor' => $paginator->previousCursor()?->encode(),
            ],
            'links' => [
                'next' => $paginator->nextPageUrl(),
                'previous' => $paginator->previousPageUrl(),
            ],
        ]);
    }
}
