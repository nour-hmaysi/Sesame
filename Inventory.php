<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'sku', 'category_id', 'costing_method', 'factor',
        'barcode', 'storage_unit', 'ingredient_unit', 'total_cost_of_production'
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function inventoryStocks()
    {
        return $this->hasMany(InventoryStock::class);
    }

    public function inventoryUsages()
    {
        return $this->hasMany(InventoryUsage::class);
    }

    public function productInventoryOrders()
    {
        return $this->hasMany(ProductInventoryOrder::class);
    }
}
