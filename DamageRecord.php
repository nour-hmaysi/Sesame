<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class DamageRecord extends Model
{
    protected $fillable = ['product_id', 'quantity', 'reason',
        'organization_id'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
