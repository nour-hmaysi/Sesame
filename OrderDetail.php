<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderDetail extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'product_id', 'quantity', 'price','organization_id'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class,'product_id');
    }
    public function ingredients()
    {
        return $this->hasMany(OrderDetailIngredient::class);
    }

    public function optionalIngredients()
    {
        return $this->belongsToMany(Ingredient::class, 'order_detail_ingredients', 'order_detail_id', 'ingredient_id')
            ->withPivot('price', 'quantity');
    }
    public function customizations()
    {
        return $this->hasMany(OrderDetailCustomization::class);
    }

    public function notes()
    {
        return $this->hasOne(OrderCustomNote::class);
    }



}
