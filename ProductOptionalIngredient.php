<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class ProductOptionalIngredient extends Model
{
    protected $fillable = ['product_id', 'ingredient_id', 'price', 'unit'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
