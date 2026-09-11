<?php
// app/Services/FraudDetectionService.php

namespace App\Services;

use App\Models\Transaction;
use App\Models\FraudAlert;
use App\Models\Member;
use Carbon\Carbon;

class FraudDetectionService
{
    /**
     * Main entry point — assess a transaction and return a risk score 0-100
     */
    public function assessTransaction(array $data): int
    {
        $score = 0;

        $score += $this->checkAmountThreshold($data['amount']);
        $score += $this->checkDailyLimit($data['member_id'], $data['amount']);
        $score += $this->checkVelocity($data['account_id']);
        $score += $this->checkTimeAnomaly();
        $score += $this->checkBehaviouralDeviation($data['member_id'], $data['amount']);
        $score += $this->checkDuplicateTransaction($data['account_id'], $data['amount']);

        return min(100, $score);
    }

    // Rule 1: Amount threshold
    private function checkAmountThreshold(float $amount): int
    {
        if ($amount >= 1000000) return 40;
        if ($amount >= 500000)  return 25;
        if ($amount >= 200000)  return 15;
        if ($amount >= 100000)  return 8;
        return 0;
    }

    // Rule 2: Daily aggregate limit (max ₦2M/day)
    private function checkDailyLimit(int $memberId, float $amount): int
    {
        $todayTotal = Transaction::where('member_id', $memberId)
            ->where('transaction_type', 'debit')
            ->whereDate('created_at', today())
            ->sum('amount');

        if (($todayTotal + $amount) > 2000000) return 30;
        if (($todayTotal + $amount) > 1000000) return 15;
        return 0;
    }

    // Rule 3: Velocity — too many transactions in short window
    private function checkVelocity(int $accountId): int
    {
        $recentCount = Transaction::where('account_id', $accountId)
            ->where('created_at', '>=', Carbon::now()->subMinutes(30))
            ->count();

        if ($recentCount >= 10) return 35;
        if ($recentCount >= 5)  return 15;
        return 0;
    }

    // Rule 4: Unusual hour (1 AM - 4 AM)
    private function checkTimeAnomaly(): int
    {
        $hour = (int) date('H');
        if ($hour >= 1 && $hour <= 4) return 20;
        return 0;
    }

    // Rule 5: Behavioural deviation (Z-score against 90-day baseline)
    private function checkBehaviouralDeviation(int $memberId, float $amount): int
    {
        $past90Days = Transaction::where('member_id', $memberId)
            ->where('transaction_type', 'debit')
            ->where('created_at', '>=', Carbon::now()->subDays(90))
            ->pluck('amount');

        if ($past90Days->count() < 5) return 0;

        $mean   = $past90Days->avg();
        $stdDev = $this->standardDeviation($past90Days->toArray());

        if ($stdDev == 0) return 0;

        $zScore = abs(($amount - $mean) / $stdDev);

        if ($zScore > 3)   return 25;
        if ($zScore > 2)   return 15;
        if ($zScore > 1.5) return 8;
        return 0;
    }

    // Rule 6: Duplicate detection (same amount within 5 minutes)
    private function checkDuplicateTransaction(int $accountId, float $amount): int
    {
        $duplicate = Transaction::where('account_id', $accountId)
            ->where('amount', $amount)
            ->where('transaction_type', 'debit')
            ->where('created_at', '>=', Carbon::now()->subMinutes(5))
            ->exists();

        return $duplicate ? 40 : 0;
    }

    // Assess device risk
    public function assessDeviceRisk(int $memberId, ?string $deviceId): int
    {
        if (!$deviceId) return 0;

        $known = Transaction::where('member_id', $memberId)
            ->where('device_id', $deviceId)
            ->exists();

        return $known ? 0 : 20;
    }

    // Create fraud alert record
    public function createAlert(int $memberId, ?int $transactionId, string $type, int $riskScore, float $amount): FraudAlert
    {
        return FraudAlert::create([
            'member_id'      => $memberId,
            'transaction_id' => $transactionId,
            'alert_type'     => $type,
            'risk_score'     => $riskScore,
            'amount'         => $amount,
            'description'    => $this->buildAlertDescription($type, $riskScore, $amount),
            'status'         => 'open',
        ]);
    }

    private function buildAlertDescription(string $type, int $score, float $amount): string
    {
        return match(true) {
            $score >= 90 => "Critical risk transaction of ₦" . number_format($amount, 2) . " blocked automatically. Risk score: {$score}/100.",
            $score >= 70 => "High risk transaction of ₦" . number_format($amount, 2) . " flagged for review. Risk score: {$score}/100.",
            default      => "Suspicious transaction of ₦" . number_format($amount, 2) . " detected. Risk score: {$score}/100.",
        };
    }

    private function standardDeviation(array $values): float
    {
        $count = count($values);
        if ($count === 0) return 0;
        $mean     = array_sum($values) / $count;
        $variance = array_sum(array_map(fn($v) => pow($v - $mean, 2), $values)) / $count;
        return sqrt($variance);
    }
}
