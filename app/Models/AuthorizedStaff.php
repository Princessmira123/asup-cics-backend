<?php
// app/Models/AuthorizedStaff.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class AuthorizedStaff extends Model {
    protected $table = 'authorized_staff';
    protected $fillable = ['staff_id', 'full_name', 'is_registered'];
    protected $casts = ['is_registered' => 'boolean'];
}
