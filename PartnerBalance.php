<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PartnerBalance extends Model
{
    use HasFactory;
    protected $table = 'partner_balance';
    protected $fillable = [
        'partner_id',
        'description',
        'amount',
        'is_debit',
        'created_by',
        'updated_by',
    ];
}
