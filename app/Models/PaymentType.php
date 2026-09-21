<?php
// app/Models/PaymentType.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PaymentType extends Model {
    protected $fillable = ['name','default_amount','active'];
    protected $casts    = ['active' => 'boolean'];
}
