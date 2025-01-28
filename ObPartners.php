<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ObPartners extends Model
{
    use HasFactory;
    protected $table = 'ob_partners';
    protected $fillable = [
        'organization_id',
        'partner_id',
        'debit_amount',
        'credit_amount',
        'created_by'
    ];

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