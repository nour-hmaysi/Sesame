<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class OrderDetailIngredient extends Model
{
    protected $fillable = [
        'order_detail_id',
        'ingredient_id',
        'price',
        'quantity','organization_id'
    ];

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function orderDetail()
    {
        return $this->belongsTo(OrderDetail::class);
    }
}
