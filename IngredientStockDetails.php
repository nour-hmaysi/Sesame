<?php

namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;



class IngredientStockDetails extends Model
{
    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('organization', function (Builder $builder) {
            $builder->where('organization_id', org_id()); // Ensure org_id() returns the current organization's ID
        });
    }
    protected $fillable = [
        'order_detail_id',
        'ingredient_id',
        'price',
        'quantity',
        'quantity_usage',
        'factor',
        'unit_buy',
        'unit_use',
        'expiry_date','organization_id'
    ];

    public function orderDetail()
    {
        return $this->belongsTo(IngredientStock::class, 'order_detail_id');
    }

//    // Define the ingredient relationship here
//    public function ingredient()
//    {
//        return $this->belongsTo(Ingredient::class);
//    }


// OrderDetailIngredient.php
    public function ingredientStock()
    {
        return $this->belongsTo(IngredientStock::class, 'order_detail_id', 'id');
    }
    public function stock()
    {
        return $this->belongsTo(IngredientStock::class, 'order_detail_id', 'id');
    }
    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class, 'ingredient_id', 'id');
    }



}
