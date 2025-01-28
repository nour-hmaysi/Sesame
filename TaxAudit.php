<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class TaxAudit extends Model
{
    use HasFactory;
    protected $table = 'tax_audit';
    protected $fillable = [
        'file',
        'start_date',
        'end_date',
        'organization_id',
        'created_by',
        'updated_by',
    ];

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