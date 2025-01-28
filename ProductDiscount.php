<?php namespace App;

use Illuminate\Database\Eloquent\Model;

class ProductDiscount extends Model
{
protected $fillable = ['product_id', 'discounted_product_id', 'new_price'];

public function product()
{
return $this->belongsTo(Product::class, 'product_id');
}

public function discountedProduct()
{
return $this->belongsTo(Product::class, 'discounted_product_id');
}
}
