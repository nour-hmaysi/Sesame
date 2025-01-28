<?php

namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;


class DamageIngredient extends Model
{
    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('organization', function (Builder $builder) {
            $builder->where('organization_id', org_id()); // Ensure org_id() returns the current organization's ID
        });
    }
    protected $fillable = [
        'ingredient_id', 'stock_id', 'quantity', 'reason', 'user_id','organization_id'

    ];

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class, 'ingredient_id');
    }

    public function stock()
    {
        return $this->belongsTo(IngredientStock::class, 'stock_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

//    public function scopeByOrganization($query)
//    {
//        return $query->where('organization_id', org_id());
//    }
}

