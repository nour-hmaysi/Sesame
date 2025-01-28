<?php

namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;
    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('organization', function (Builder $builder) {
            $builder->where('organization_id', org_id()); // Ensure org_id() returns the current organization's ID
        });
    }
    protected $fillable = ['name', 'contact_info', 'location', 'item_type','organization_id'];

    public function inventoryStocks()
    {
        return $this->hasMany(InventoryStock::class);
    }
}
