<?php
// app/Models/Notification.php
//
// This model was referenced by NotificationController but never actually
// defined anywhere in the delivered codebase — every call to
// \App\Models\Notification would have thrown a "class not found" error.
// Added here for real, matching the existing `notifications` migration.
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model {
    protected $fillable = ['member_id','type','title','message','is_read','delivery_channel','sent_at'];
    protected $casts    = ['is_read' => 'boolean', 'sent_at' => 'datetime'];
    public function member() { return $this->belongsTo(Member::class); }
}
