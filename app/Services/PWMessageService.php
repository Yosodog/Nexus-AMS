<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PWMessageService
{
    protected string $apiKey;
    protected string $endpoint = "https://politicsandwar.com/api/send-message/";

    /**
     *
     */
    public function __construct()
    {
        $this->apiKey = env("PW_API_KEY");
    }

    /**
     * @param  int  $nation_id
     * @param  string  $subject
     * @param  string  $message
     *
     * @return bool
     */
    public function sendMessage(int $nation_id, string $subject, string $message): bool
    {
        $payload = [
            "key" => $this->apiKey,
            "to" => $nation_id,
            "subject" => $subject,
            "message" => $message,
        ];

        try {
            $response = Http::asForm()->post($this->endpoint, $payload);

            if ($response->successful()) {
                return true;
            } else {
                Log::error("PNW Message Failed", ['response' => $response->body()]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error("PNW Message Exception", ['error' => $e->getMessage()]);
            return false;
        }
    }
}
