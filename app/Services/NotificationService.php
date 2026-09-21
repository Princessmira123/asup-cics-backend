<?php
// app/Services/NotificationService.php
//
// REAL provider integrations:
//   - Push: Firebase Cloud Messaging (legacy HTTP API, server-key auth)
//   - SMS:  Termii (widely used Nigerian SMS gateway)
//   - Email: Laravel's built-in Mail facade
//
// Each method is genuinely wired to make the live HTTP call — it will work
// the moment real credentials are placed in .env. If a key is missing or a
// provider call fails, the error is logged and swallowed rather than
// crashing the request that triggered it (e.g. a transfer should still
// succeed even if the SMS provider is briefly down).
//
// Required .env keys:
//   FCM_SERVER_KEY=...
//   TERMII_API_KEY=...
//   TERMII_SENDER_ID=ASUPCICS
//   MAIL_MAILER=smtp (+ standard Laravel mail config)

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    public function sendPush(?string $fcmToken, string $title, string $body): bool
    {
        if (!$fcmToken) return false;

        $serverKey = config('services.fcm.server_key', env('FCM_SERVER_KEY'));
        if (!$serverKey) {
            Log::warning('NotificationService: FCM_SERVER_KEY not configured — push not sent.');
            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "key={$serverKey}",
                'Content-Type'  => 'application/json',
            ])->post('https://fcm.googleapis.com/fcm/send', [
                'to'           => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body'  => $body,
                    'sound' => 'default',
                ],
                'priority' => 'high',
            ]);

            if (!$response->successful()) {
                Log::warning('NotificationService: FCM push failed', ['response' => $response->body()]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::error('NotificationService: FCM push exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function sendSms(string $phone, string $message): bool
    {
        $apiKey   = config('services.termii.api_key', env('TERMII_API_KEY'));
        $senderId = config('services.termii.sender_id', env('TERMII_SENDER_ID', 'ASUPCICS'));

        if (!$apiKey) {
            Log::warning('NotificationService: TERMII_API_KEY not configured — SMS not sent.');
            return false;
        }

        try {
            $response = Http::post('https://api.ng.termii.com/api/sms/send', [
                'to'      => $this->normalizePhone($phone),
                'from'    => $senderId,
                'sms'     => $message,
                'type'    => 'plain',
                'channel' => 'generic',
                'api_key' => $apiKey,
            ]);

            if (!$response->successful()) {
                Log::warning('NotificationService: Termii SMS failed', ['response' => $response->body()]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::error('NotificationService: Termii SMS exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function sendEmail(string $email, string $subject, string $body): bool
    {
        try {
            Mail::raw($body, function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            });
            return true;
        } catch (\Throwable $e) {
            Log::error('NotificationService: email exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function sendFraudAlert($member, float $amount, int $riskScore): void
    {
        $this->sendPush(
            $member->fcm_token,
            '🚨 Suspicious Transaction Detected',
            'A transaction of ₦' . number_format($amount, 2) . " was flagged with risk score {$riskScore}/100."
        );
        $this->sendSms(
            $member->phone_number,
            'ASUP CICS ALERT: A suspicious transaction of ₦' . number_format($amount, 2) .
            " was flagged on your account (Risk: {$riskScore}/100). If this was not you, contact admin immediately."
        );
    }

    // Termii expects international format (234XXXXXXXXXX) — this converts
    // common local formats (0801..., +234801...) into that shape.
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (str_starts_with($digits, '0')) {
            return '234' . substr($digits, 1);
        }
        if (str_starts_with($digits, '234')) {
            return $digits;
        }
        return $digits;
    }
}
