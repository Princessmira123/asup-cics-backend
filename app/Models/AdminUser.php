<?php
// app/Models/AdminUser.php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class AdminUser extends Authenticatable {
    use HasApiTokens;
    protected $fillable = ['admin_id','full_name','email','password_hash','role','last_login'];
    protected $hidden   = ['password_hash'];
    public function getAuthPassword() { return $this->password_hash; }
}
