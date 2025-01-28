<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class DamageProduct extends Model
{
    protected static function boot()
{
    parent::boot();

    static::addGlobalScope('organization', function (Builder $builder) {
        if (org_id()) {
            $builder->where('organization_id', org_id());
        }
    });
}

    protected $fillable = [
        'product_id', 'quantity', 'reason', 'user_id',
        'organization_id'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
