<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentTerms extends Model
{
    use HasFactory;
    protected $table = 'payment_terms';
    protected $fillable = [
        'name',
        'value',
        'organization_id',
        'created_by',
        'updated_by',
    ];
}
