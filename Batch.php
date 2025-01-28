<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Batch extends Model
{
    use HasFactory;

    protected $fillable = ['inventory_id', 'batch_number', 'expiry_date'];

    public function inventory()
    {
        return $this->belongsTo(Inventory::class);
    }
}
