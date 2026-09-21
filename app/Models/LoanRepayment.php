<?php
// app/Models/LoanRepayment.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class LoanRepayment extends Model {
    protected $fillable = ['loan_id','amount_paid','balance_remaining','payment_date','status'];
    public function loan() { return $this->belongsTo(Loan::class); }
}
