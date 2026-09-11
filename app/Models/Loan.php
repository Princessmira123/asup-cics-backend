<?php
// app/Models/Loan.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Loan extends Model {
    protected $fillable = ['loan_id','member_id','amount_requested','amount_approved',
        'interest_rate','tenure_months','purpose','description','commence_month',
        'commence_year','status','approved_by','denied_by','denial_reason',
        'application_date','approval_date','disbursement_date','next_payment_date'];
    public function member()     { return $this->belongsTo(Member::class); }
    public function guarantors() { return $this->hasMany(LoanGuarantor::class); }
    public function repayments() { return $this->hasMany(LoanRepayment::class); }
}
