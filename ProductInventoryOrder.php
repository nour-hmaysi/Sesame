<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductInventoryOrder extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'inventory_id', 'is_default', 'is_optional', 'cost'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function inventory()
    {
        return $this->belongsTo(Inventory::class);
    }
}
