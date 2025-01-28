<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class OrderDetailCustomization extends Model
{
     protected $fillable = ['order_detail_id', 'product_id', 'ingredient_id', 'quantity', 'price', 'type','organization_id'];

    public function orderDetail()
    {
        return $this->belongsTo(OrderDetail::class);
    }
    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id'); // Change 'ingredient_id' to 'product_id' if required
    }
}
