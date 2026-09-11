<?php
// app/Models/Account.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Account extends Model {
    protected $fillable = ['member_id','account_type','balance','savings_balance',
        'loan_balance','shares_balance','interest_rate','date_opened','status'];
    public function member() { return $this->belongsTo(Member::class); }
    public function transactions() { return $this->hasMany(Transaction::class); }
}
