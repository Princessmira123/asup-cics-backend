<?php
// app/Models/FraudAlert.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class FraudAlert extends Model {
    protected $fillable = ['member_id','transaction_id','alert_type','risk_score',
        'amount','description','status','resolved_by','resolution_notes','resolved_at'];
    public function member()      { return $this->belongsTo(Member::class); }
    public function transaction() { return $this->belongsTo(Transaction::class); }
}
