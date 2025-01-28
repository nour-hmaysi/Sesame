<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Asset extends Model
{
    use HasFactory;
    protected $table = 'fixed_asset';
    protected $fillable = [
        'asset_account_id',
        'asset_type',
        'description',
        'unit',
        'cost',
        'net_cost',
        'lifetime',
        'vat_amount',
        'is_vat',
        'salvage_value',
        'book_value',
        'depreciation_type',
        'depreciation_value',
        'repetitive',
        'depreciation_account_id',
        'reference_number',
        'payment_account_id',
        'location',
        'paid_through',
        'payable_id',
        'date',
        'receipt',
        'dep_date',
        'end_date',
        'status',
        'deleted',
        'organization_id',
        'created_by',
        'updated_by',
    ];
    public function assetType()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'asset_account_id');
    }
    public function account()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'depreciation_account_id');
    }
    public function paymentAccount()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'payment_account_id');
    }
    public function supplier()
    {
        return $this->belongsTo(Partner::class, 'payable_id');
    }
    public function DepType()
    {
        return $this->belongsTo(DepreciationType::class, 'depreciation_type');
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($asset) {
            $asset->organization_id = org_id();
            $asset->created_by = Auth::id();
        });
          static::updating(function ($data) {
            $data->updated_by = Auth::id();
        });
    }
}