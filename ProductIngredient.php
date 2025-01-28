<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ProductIngredient extends Model
{
protected $fillable = ['product_id', 'ingredient_id', 'unit', 'is_optional', 'price','organization_id'];

public function product()
{
return $this->belongsTo(Product::class);
}

public function ingredient()
{
return $this->belongsTo(Ingredient::class);
}
    public function isOptional()
    {
        return $this->is_optional;
    }
    public function productIngredients()
    {
        return $this->hasMany(ProductIngredient::class)->where('organization_id', org_id());
    }
//    public function ingredientStockDetails()
//    {
//        return $this->hasManyThrough(
//            IngredientStockDetails::class,
//            IngredientStock::class,
//            'ingredient_id', // Foreign key on IngredientStock
//            'order_detail_id', // Foreign key on IngredientStockDetails
//            'ingredient_id', // Local key on ProductIngredient
//            'id' // Local key on IngredientStock
//        );
//    }


    public function ingredientStockDetails()
    {
        return $this->hasManyThrough(
            IngredientStockDetails::class,
            IngredientStock::class,
             'order_number',    // Foreign key on IngredientStockDetails
            'id',                 // Local key on ProductIngredient
            'id'                  // Local key on IngredientStock
        );
    }


}
