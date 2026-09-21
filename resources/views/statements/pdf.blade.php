<!-- resources/views/statements/pdf.blade.php -->
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Account Statement</title>
    <style>
        @page { margin: 24px 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }

        .header { border-bottom: 3px solid #1e3a5f; padding-bottom: 10px; margin-bottom: 14px; }
        .header table { width: 100%; }
        .society-name { font-size: 16px; font-weight: bold; color: #1e3a5f; }
        .society-sub { font-size: 10px; color: #555; }
        .doc-title { font-size: 13px; font-weight: bold; text-align: right; color: #1e3a5f; }
        .doc-sub { font-size: 10px; text-align: right; color: #555; }

        .info-box { background: #f4f6f8; border: 1px solid #dde3e8; border-radius: 4px; padding: 10px 14px; margin-bottom: 14px; }
        .info-box table { width: 100%; }
        .info-label { color: #666; font-size: 9px; text-transform: uppercase; }
        .info-value { font-size: 11px; font-weight: bold; color: #1a1a1a; }

        .summary { width: 100%; margin-bottom: 16px; }
        .summary td { width: 33.33%; padding: 10px; }
        .summary-box { border: 1px solid #dde3e8; border-radius: 4px; padding: 10px; text-align: center; }
        .summary-box .label { font-size: 9px; color: #666; text-transform: uppercase; }
        .summary-box .value { font-size: 14px; font-weight: bold; margin-top: 4px; }
        .credit-color { color: #16a34a; }
        .debit-color { color: #dc2626; }
        .net-color { color: #1e3a5f; }

        table.txns { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.txns th { background: #1e3a5f; color: #fff; font-size: 9px; text-transform: uppercase; padding: 7px 8px; text-align: left; }
        table.txns td { padding: 6px 8px; border-bottom: 1px solid #eceef1; font-size: 10px; }
        table.txns tr:nth-child(even) td { background: #f8f9fb; }
        .amount-credit { color: #16a34a; font-weight: bold; }
        .amount-debit { color: #dc2626; font-weight: bold; }
        .ref { font-family: DejaVu Sans Mono, monospace; font-size: 9px; color: #777; }

        .footer { margin-top: 24px; padding-top: 10px; border-top: 1px solid #dde3e8; font-size: 8.5px; color: #888; text-align: center; }
        .empty { text-align: center; padding: 30px; color: #888; }
    </style>
</head>
<body>

    <div class="header">
        <table>
            <tr>
                <td style="width: 60%;">
                    <div class="society-name">ASUP CICS &mdash; Senior Staff Cooperative Society</div>
                    <div class="society-sub">Ede Cooperative Society, Federal Polytechnic Ede</div>
                </td>
                <td style="width: 40%;">
                    <div class="doc-title">ACCOUNT STATEMENT</div>
                    <div class="doc-sub">Generated {{ now()->format('d M Y, h:i A') }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="info-box">
        <table>
            <tr>
                <td style="width: 25%;">
                    <div class="info-label">Member Name</div>
                    <div class="info-value">{{ $member->full_name }}</div>
                </td>
                <td style="width: 25%;">
                    <div class="info-label">Account Number</div>
                    <div class="info-value">{{ $member->account_number }}</div>
                </td>
                <td style="width: 25%;">
                    <div class="info-label">Statement Period</div>
                    <div class="info-value">{{ \Carbon\Carbon::parse($from)->format('d M Y') }} &ndash; {{ \Carbon\Carbon::parse($to)->format('d M Y') }}</div>
                </td>
                <td style="width: 25%;">
                    <div class="info-label">Staff ID</div>
                    <div class="info-value">{{ $member->staff_id }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="summary">
        <tr>
            <td>
                <div class="summary-box">
                    <div class="label">Total Credits</div>
                    <div class="value credit-color">&#8358;{{ number_format($credits, 2) }}</div>
                </div>
            </td>
            <td>
                <div class="summary-box">
                    <div class="label">Total Debits</div>
                    <div class="value debit-color">&#8358;{{ number_format($debits, 2) }}</div>
                </div>
            </td>
            <td>
                <div class="summary-box">
                    <div class="label">Net Movement</div>
                    <div class="value net-color">&#8358;{{ number_format($credits - $debits, 2) }}</div>
                </div>
            </td>
        </tr>
    </table>

    @if($transactions->isEmpty())
        <div class="empty">No transactions were recorded during this period.</div>
    @else
        <table class="txns">
            <thead>
                <tr>
                    <th style="width: 12%;">Date</th>
                    <th style="width: 40%;">Description</th>
                    <th style="width: 18%;">Reference</th>
                    <th style="width: 15%;">Type</th>
                    <th style="width: 15%; text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($transactions as $t)
                <tr>
                    <td>{{ $t->created_at->format('d M Y') }}</td>
                    <td>{{ $t->description }}</td>
                    <td class="ref">TXN-{{ $t->id }}</td>
                    <td style="text-transform: capitalize;">{{ $t->transaction_type }}</td>
                    <td style="text-align: right;" class="{{ $t->transaction_type === 'credit' ? 'amount-credit' : 'amount-debit' }}">
                        {{ $t->transaction_type === 'credit' ? '+' : '-' }}&#8358;{{ number_format($t->amount, 2) }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        This is a system-generated statement from the ASUP CICS Cooperative Banking Application and does not require a signature.<br>
        For enquiries, please contact the cooperative office at Federal Polytechnic Ede.
    </div>

</body>
</html>
