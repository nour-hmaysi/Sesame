<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ObAdjustment extends Model
{
    use HasFactory;
    protected $table = 'ob_adjustment';
    protected $fillable = [
        'date',
        'organization_id'
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