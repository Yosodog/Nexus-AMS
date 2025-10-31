<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PWMessageService
{
    protected string $apiKey;

    protected string $endpoint = 'https://politicsandwar.com/api/send-message/';

    public function __construct()
    {
        $this->apiKey = env('PW_API_KEY');
    }

    public function sendMessage(int $nation_id, string $subject, string $message): bool
    {
        $payload = [
            'key' => $this->apiKey,
            'to' => $nation_id,
            'subject' => $subject,
            'message' => $message,
        ];

        try {
            $response = Http::asForm()->post($this->endpoint, $payload);

            if ($response->successful()) {
                $json = $response->json();

                if (is_array($json) && array_key_exists('success', $json)) {
                    if (! filter_var($json['success'], FILTER_VALIDATE_BOOLEAN)) {
                        Log::warning('PNW Message Rejected', [
                            'nation_id' => $nation_id,
                            'subject' => $subject,
                            'response' => $json,
                        ]);

                        return false;
                    }
                }

                return true;
            } else {
                Log::error('PNW Message Failed', ['response' => $response->body()]);

                return false;
            }
        } catch (Exception $e) {
            Log::error('PNW Message Exception', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
