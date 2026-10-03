<?php
// app/Services/NotificationService.php
//
// REAL provider integrations:
//   - Push: Firebase Cloud Messaging (legacy HTTP API, server-key auth)
//   - SMS:  Termii (widely used Nigerian SMS gateway)
//   - Email: Resend's HTTPS API (NOT raw SMTP — see sendEmail() below for
//     why: Railway, and likely other PaaS hosts, block outbound SMTP
//     entirely on free tiers, which is what was actually breaking
//     registration this whole time)
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
//   RESEND_API_KEY=...
//   RESEND_FROM_ADDRESS="ASUP CICS <onboarding@resend.dev>"   (optional — this default works with zero setup)

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    // CHANGED FROM RAW SMTP TO RESEND'S HTTP API.
    //
    // This used to go through Laravel's Mail facade (Mail::raw(), raw SMTP
    // socket to smtp.gmail.com:587). That's what was actually breaking
    // registration: Railway blocks outbound SMTP entirely on Free/Trial/
    // Hobby plans (confirmed directly from Railway's own support
    // responses) — the connection would just hang until PHP's 30-second
    // execution limit killed the whole request with a Fatal Error, which
    // is why the app only ever saw a dead connection/timeout, never an
    // actual error message, and the member never reached the OTP screen
    // even though the Member record itself had already been created.
    //
    // Switching to Resend's plain HTTPS API sidesteps this completely —
    // ordinary HTTPS (port 443) isn't blocked the way raw SMTP ports are,
    // and this will keep working the same way on Render or any other host
    // later, since nothing here depends on SMTP ports being open at all.
    //
    // Required .env key: RESEND_API_KEY=... (free tier, no domain
    // verification needed if sending from the default onboarding@resend.dev
    // address — see RESEND_FROM_ADDRESS below).
    public function sendEmail(string $email, string $subject, string $body): bool
    {
        $apiKey = config('services.resend.api_key', env('RESEND_API_KEY'));
        $from   = config('services.resend.from_address', env('RESEND_FROM_ADDRESS', 'ASUP CICS <onboarding@resend.dev>'));

        if (!$apiKey) {
            Log::warning('NotificationService: RESEND_API_KEY not configured — email not sent.');
            return false;
        }

        try {
            $response = Http::withToken($apiKey)->post('https://api.resend.com/emails', [
                'from'    => $from,
                'to'      => [$email],
                'subject' => $subject,
                'text'    => $body,
            ]);

            if (!$response->successful()) {
                Log::error('NotificationService: Resend email failed', ['response' => $response->body()]);
                return false;
            }
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

    // ── FRAUD ALERT — ADMIN EMAIL ────────────────────────────────────────────
    // The member-facing sendFraudAlert() above relies on push (needs
    // FCM_SERVER_KEY) and SMS (needs TERMII_API_KEY) — both silently do
    // nothing if those aren't configured, which means an admin could have
    // zero way of ever finding out an alert was raised, short of manually
    // opening the Fraud Management tab. Email already works reliably (Gmail
    // SMTP), so this is the one channel guaranteed to actually reach
    // someone the moment PAYSTACK/MAIL config exists — no extra service
    // signup needed.
    public function sendFraudAlertToAdmins(string $memberName, string $memberIdentifier, float $amount, int $riskScore, string $alertType): void
    {
        $admins = \App\Models\AdminUser::pluck('email');
        if ($admins->isEmpty()) return;

        $subject = $riskScore >= 90 ? '🚨 BLOCKED — Critical Fraud Alert' : '⚠️ Fraud Alert Flagged for Review';
        $body = "A transaction has been flagged by fraud detection.\n\n"
              . "Member: {$memberName} ({$memberIdentifier})\n"
              . "Amount: ₦" . number_format($amount, 2) . "\n"
              . "Risk Score: {$riskScore}/100\n"
              . "Type: {$alertType}\n"
              . ($riskScore >= 90 ? "\nThis transaction was automatically BLOCKED and did not go through.\n" : "\nThis transaction went through but was flagged for review.\n")
              . "\nOpen the admin panel's Fraud Management tab to review.";

        foreach ($admins as $email) {
            $this->sendEmail($email, $subject, $body);
        }
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
