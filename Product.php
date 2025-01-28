<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Product extends Model
{
    protected static function boot()
{
    parent::boot();

    static::addGlobalScope('organization', function (Builder $builder) {
        $builder->where('organization_id', org_id()); // Ensure org_id() returns the current organization's ID
    });
}

    protected $fillable = ['name', 'category_id', 'description','price', 'total_cost', 'sku', 'barcode', 'storage_unit', 'ingredient_unit', 'costing_method', 'image',
        'organization_id'];

    public function productIngredients()
    {
        return $this->hasMany(ProductIngredient::class, 'product_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
    public function ingredients()
    {
        return $this->hasMany(ProductIngredient::class, 'product_id');
    }


//    public function productIngredients()
//    {
//        return $this->hasMany(ProductIngredient::class);
//    }


    public function optionalIngredients()
    {
        return $this->hasMany(ProductOptionalIngredient::class);
    }

    public function productDiscount()
    {
        return $this->hasMany(ProductDiscount::class);
    }
    public function productOptionalIngredients()
    {
        return $this->hasMany(ProductOptionalIngredient::class);
    }

    public function productIngredientOrders()
    {
        return $this->hasMany(ProductIngredientOrder::class);
    }

    public function productCategory()
    {
        return $this->belongsTo(Category::class);
    }
    public function calculateTotalCost()
    {
        $totalCost = 0;
        foreach ($this->productIngredients as $ingredient) {
            $totalCost += $ingredient->unit * $ingredient->ingredient->cost; // Assuming 'cost' is a field in Ingredient
        }
        return $totalCost;
    }
    // New Relationship
    public function ingredientsWithStocks()
    {
        return $this->hasMany(ProductIngredient::class, 'product_id')
            ->with('ingredient.stocks'); // Chain to get ingredient stocks via ProductIngredient
    }


}

