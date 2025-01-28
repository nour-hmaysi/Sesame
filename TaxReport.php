<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class TaxReport extends Model
{
    use HasFactory;
    protected $table = 'tax_report';
    protected $fillable = [
        'taxable_amount',
        'amount_due',
        'start_date',
        'end_date',
        'filed_on',
        'is_approved',
        'is_paid',
        'organization_id',
        'created_by',
        'updated_by',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($asset) {
            $asset->is_approved = 0;
            $asset->is_paid = 0;
            $asset->organization_id = org_id();
            $asset->created_by = Auth::id();
        });
          static::updating(function ($data) {
            $data->updated_by = Auth::id();
        });
    }
}