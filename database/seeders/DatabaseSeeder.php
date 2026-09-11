<?php
// database/seeders/DatabaseSeeder.php

namespace Database\Seeders;

use App\Models\Member;
use App\Models\AdminUser;
use App\Models\Account;
use App\Models\Loan;
use App\Models\LoanGuarantor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── ADMIN USER ────────────────────────────────────────────────────────
        AdminUser::create([
            'admin_id'      => 'ADM-2024-001',
            'full_name'     => 'Cooperative Administrator',
            'email'         => 'admin@asupcics.ng',
            'password_hash' => Hash::make('Admin@2024'),
            'role'          => 'admin',
        ]);

        // ── MEMBERS ───────────────────────────────────────────────────────────
        $members = [
            ['Adeyemi Oluwaseun',    'SS/EDU/2019/047', 'a.oluwaseun@asupcics.ng',  '08012345678', 'Accounting'],
            ['Bello Rasheed',        'SS/EDU/2018/031', 'b.rasheed@asupcics.ng',     '08023456789', 'Engineering'],
            ['Fatima Adeleke',       'SS/EDU/2020/055', 'f.adeleke@asupcics.ng',     '08034567890', 'Administration'],
            ['Chukwuemeka Nwosu',    'SS/EDU/2017/022', 'c.nwosu@asupcics.ng',       '08045678901', 'Sciences'],
            ['Amina Lawal',          'SS/EDU/2021/068', 'a.lawal@asupcics.ng',       '08056789012', 'Library'],
            ['Samuel Okafor',        'SS/EDU/2016/011', 's.okafor@asupcics.ng',      '08067890123', 'Engineering'],
        ];

        $balances = [847500, 960000, 780000, 1440000, 620000, 1700000];
        $savings  = [612000, 480000, 390000, 720000,  310000, 850000];

        foreach ($members as $i => [$name, $staffId, $email, $phone, $dept]) {
            $member = Member::create([
                'member_id'       => 'MBR-2024-00' . ($i + 1),
                'full_name'       => $name,
                'staff_id'        => $staffId,
                'email'           => $email,
                'phone_number'    => $phone,
                'password_hash'   => Hash::make('Password@123'),
                'transaction_pin' => Hash::make('1234'),
                'account_number'  => '990011' . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'nin'             => str_pad(rand(10000000000, 99999999999), 11, '0'),
                'address'         => ($i + 1) . ' Staff Quarters, Federal Polytechnic, Ede',
                'department'      => $dept,
                'status'          => 'active',
            ]);

            Account::create([
                'member_id'       => $member->id,
                'account_type'    => 'savings',
                'balance'         => $balances[$i],
                'savings_balance' => $savings[$i],
                'loan_balance'    => $i === 0 ? 150000 : 0,
                'shares_balance'  => $savings[$i] * 0.3,
                'interest_rate'   => 7.00,
                'date_opened'     => now()->subYears(2),
                'status'          => 'active',
            ]);
        }

        // ── SAMPLE ACTIVE LOAN ────────────────────────────────────────────────
        $borrower  = Member::where('staff_id', 'SS/EDU/2019/047')->first();
        $guarantor1 = Member::where('staff_id', 'SS/EDU/2018/031')->first();
        $guarantor2 = Member::where('staff_id', 'SS/EDU/2020/055')->first();

        $loan = Loan::create([
            'loan_id'           => 'LN-2024-003',
            'member_id'         => $borrower->id,
            'amount_requested'  => 500000,
            'amount_approved'   => 500000,
            'interest_rate'     => 12,
            'tenure_months'     => 24,
            'purpose'           => 'Home Renovation',
            'commence_month'    => 'May',
            'commence_year'     => 2024,
            'status'            => 'active',
            'application_date'  => now()->subMonths(3),
            'approval_date'     => now()->subMonths(3)->addDays(5),
            'disbursement_date' => now()->subMonths(2),
            'next_payment_date' => now()->addDays(15),
        ]);

        LoanGuarantor::create([
            'loan_id'       => $loan->id,
            'member_id'     => $guarantor1->id,
            'consent_token' => Str::random(40),
            'status'        => 'accepted',
            'consent_date'  => now()->subMonths(3)->addDays(2),
        ]);
        LoanGuarantor::create([
            'loan_id'       => $loan->id,
            'member_id'     => $guarantor2->id,
            'consent_token' => Str::random(40),
            'status'        => 'accepted',
            'consent_date'  => now()->subMonths(3)->addDays(3),
        ]);

        $this->command->info('✓ Database seeded successfully.');
        $this->command->info('  Admin login: admin@asupcics.ng / Admin@2024');
        $this->command->info('  Member login: a.oluwaseun@asupcics.ng / Password@123 / PIN: 1234');
    }
}
