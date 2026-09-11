<?php
// app/Models/OtpCode.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model {
    protected $fillable = ['member_id','code','type','used','expires_at'];
    protected $casts    = ['used' => 'boolean', 'expires_at' => 'datetime'];
    public function member() { return $this->belongsTo(Member::class); }
}
