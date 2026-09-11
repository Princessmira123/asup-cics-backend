<?php
// app/Models/HouseholdRequest.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class HouseholdRequest extends Model {
    protected $fillable = ['member_id','operation_type','amount_requested','description','status','admin_notes'];
    public function member() { return $this->belongsTo(Member::class); }
}
