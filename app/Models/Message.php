<?php
// app/Models/Message.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Message extends Model {
    protected $fillable = ['member_id','sender_type','admin_id','subject','body','is_read'];
    protected $casts    = ['is_read' => 'boolean'];
    public function member() { return $this->belongsTo(Member::class); }
    public function admin()  { return $this->belongsTo(AdminUser::class, 'admin_id'); }
}
