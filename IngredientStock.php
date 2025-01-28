<?php

namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;



class IngredientStock extends Model
{
    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('organization', function (Builder $builder) {
            $builder->where('organization_id', org_id()); // Ensure org_id() returns the current organization's ID
        });
    }
    protected $fillable = [
        'supplier_id', 'supplier_invoice_date', 'order_number',
        'supplier_invoice_number', 'total_price' ,'organization_id','unit_use'
    ];

// IngredientStock.php
    public function IngredientStockDetails()
    {
        return $this->hasMany(IngredientStockDetails::class, 'order_detail_id', 'id');
    }

    public function productIngredients()
    {
        return $this->hasMany(ProductIngredient::class)->where('organization_id', org_id());
    }


//    public function supplier()
//    {
//        return $this->belongsTo(Supplier::class);
//    }
//    public function ingredient()
//    {
//        return $this->belongsTo(Ingredient::class);
//    }
    public function scopeByOrganization($query)
    {
        return $query->where('organization_id', org_id());
    }

    public function details()
    {
        return $this->hasMany(IngredientStockDetails::class, 'order_detail_id');
    }
    // IngredientStock.php
    public function ingredient()
    {
        return $this->hasOneThrough(
            Ingredient::class,
            IngredientStockDetails::class,
            'order_detail_id', // Foreign key on IngredientStockDetails table
            'id',              // Foreign key on Ingredient table
            'id',              // Local key on IngredientStock table
            'ingredient_id'    // Local key on IngredientStockDetails table
        );
    }


    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }



}
