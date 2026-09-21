<?php
// app/Models/Payment.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model {
    protected $fillable = ['member_id','payment_type_id','payment_type_label','amount','reference_number','status'];
    public function member()      { return $this->belongsTo(Member::class); }
    public function paymentType() { return $this->belongsTo(PaymentType::class); }
}
