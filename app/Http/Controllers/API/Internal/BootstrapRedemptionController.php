<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Internal;

use App\Exceptions\BootstrapIntrospectionException;
use App\Exceptions\BootstrapRedemptionException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CaptureBootstrapTokenHash;
use App\Http\Requests\API\Internal\BootstrapRedemptionRequest;
use App\Services\TenantControl\BootstrapRedemptionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class BootstrapRedemptionController extends Controller
{
    public function __invoke(
        BootstrapRedemptionRequest $request,
        BootstrapRedemptionService $redemptions,
    ): JsonResponse {
        $tokenHash = $request->attributes->get(CaptureBootstrapTokenHash::HASH_ATTRIBUTE);

        if (! is_string($tokenHash) || preg_match('/\A[a-f0-9]{64}\z/D', $tokenHash) !== 1) {
            $request->scrubCredentials();

            return $this->failure(
                'Bootstrap could not be completed.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                false,
            );
        }

        $identity = $request->identity();

        try {
            $result = $redemptions->redeem($tokenHash, $identity);
        } catch (BootstrapIntrospectionException $exception) {
            return $this->failure(
                $exception->getMessage(),
                $exception->httpStatus,
                $exception->retryable,
            );
        } catch (BootstrapRedemptionException $exception) {
            return $this->failure($exception->getMessage(), $exception->httpStatus, false);
        } finally {
            $request->scrubCredentials();
        }

        return new JsonResponse([
            'redemption_id' => $result->redemptionId,
            'local_user_id' => $result->localUserId,
            'mode' => $result->mode->value,
        ], $result->mode->value === 'created'
            ? Response::HTTP_CREATED
            : Response::HTTP_OK, [
                'Cache-Control' => 'no-store, private',
            ]);
    }

    private function failure(string $message, int $status, bool $retryable): JsonResponse
    {
        return new JsonResponse([
            'message' => $message,
            'retryable' => $retryable,
        ], $status, [
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
