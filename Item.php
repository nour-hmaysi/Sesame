<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Item extends Model
{
    use HasFactory;
    protected $table = 'item';
    protected $fillable = [
        'item_type',
        'name',
        'sku',
        'unit',
        'sale_price',
        'sale_account',
        'sale_description',
        'sale_tax',
        'purchase_price',
        'purchase_account',
        'purchase_description',
        'purchase_tax',
        'purchase_supplier_id',
        'track_inventory',
        'inventory_account',
        'opening_stock',
        'stock_quantity',
        'deleted',
        'organization_id',
        'created_by',
        'updated_by',
    ];
    public function invoices()
    {
        return $this->belongsToMany(Invoice::class, 'invoice_has_items')
            ->withPivot('tax', 'discount', 'account_id')
            ->withTimestamps();
    }
    public function account()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'sale_account');
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($data) {
            $data->organization_id = org_id();
            $data->created_by = Auth::id();
        });
          static::updating(function ($data) {
            $data->updated_by = Auth::id();
        });
    }
}