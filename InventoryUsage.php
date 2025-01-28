<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryUsage extends Model
{
    use HasFactory;

    protected $fillable = ['inventory_id', 'quantity_used'];

    public function inventory()
    {
        return $this->belongsTo(Inventory::class);
    }
}
