<?php
// app/Models/Transaction.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model {
    protected $fillable = ['account_id','member_id','transaction_type','amount',
        'reference_number','description','risk_score','fraud_flag','device_id','status'];
    protected $casts = ['fraud_flag' => 'boolean'];
    public function member()  { return $this->belongsTo(Member::class); }
    public function account() { return $this->belongsTo(Account::class); }
    public function fraudAlert() { return $this->hasOne(FraudAlert::class); }
}
