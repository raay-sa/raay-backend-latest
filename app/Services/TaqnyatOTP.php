<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TaqnyatOTP
{

    protected $sender;
    protected $bearerToken;

    public function __construct()
    {
        $this->sender = config('services.taqnyat.sender');
        $this->bearerToken = config('services.taqnyat.bearer_token');
    }


    public function sendOTP($phone, $otp)
    {
        $phone = trim($phone);
        $phone = ltrim($phone, '+');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->bearerToken,
            'Content-Type' => 'application/json',
        ])->post('https://api.taqnyat.sa/v1/messages', [
            'recipients' => [$phone],
            'body' =>
                'رمز التحقق الخاص بك: ' . $otp .
                ' لتسجيل الدخول إلى منصة راي: ' . env('FRONTEND_URL', 'http://localhost:3000'),
            'sender' => $this->sender,
            'priority' => 'high',
        ]);

        log::info("SMS Response: " . $response->body());

        return $response->successful();
    }
}
