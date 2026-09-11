<?php
// app/Models/Member.php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Member extends Authenticatable {
    use HasApiTokens;
    protected $fillable = ['member_id','full_name','staff_id','email','phone_number','password_hash',
        'transaction_pin','biometric_token','biometric_secret_hash','biometric_device_id',
        'biometric_device_name','biometric_enrolled_at','account_number','nin','date_of_birth','address',
        'department','fcm_token','status','last_login'];
    protected $hidden = ['password_hash','transaction_pin','biometric_token','biometric_secret_hash'];
    public function account() { return $this->hasOne(Account::class); }
    public function loans() { return $this->hasMany(Loan::class); }
    public function transactions() { return $this->hasMany(Transaction::class); }
    public function messages() { return $this->hasMany(Message::class); }
    public function householdRequests() { return $this->hasMany(HouseholdRequest::class); }
    public function payments() { return $this->hasMany(Payment::class); }
    public function getAuthPassword() { return $this->password_hash; }
}
