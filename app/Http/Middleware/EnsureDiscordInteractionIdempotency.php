<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Discord\DiscordCommandReceiptService;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureDiscordInteractionIdempotency
{
    public function __construct(private readonly DiscordCommandReceiptService $receipts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->attributes->get(ResolveDiscordActor::ACTOR_ATTRIBUTE);

        if (! $actor instanceof User) {
            return response()->json([
                'error' => [
                    'code' => 'discord_actor_missing',
                    'message' => 'Discord actor resolution must run before idempotency handling.',
                ],
                'meta' => ['contract_version' => 1],
            ], 500);
        }

        ['receipt' => $receipt, 'response' => $response] = $this->receipts->claim($request, $actor);

        if ($response) {
            return $response;
        }

        try {
            return $this->receipts->complete($receipt, $next($request));
        } catch (HttpResponseException $exception) {
            return $this->receipts->complete($receipt, $exception->getResponse());
        } catch (ValidationException $exception) {
            return $this->receipts->complete($receipt, response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'The request payload is invalid.',
                    'details' => $exception->errors(),
                ],
                'meta' => ['contract_version' => 1],
            ], 422));
        } catch (Throwable $exception) {
            $this->receipts->fail($receipt);

            throw $exception;
        }
    }
}
