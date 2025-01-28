<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Tax extends Model
{
    use HasFactory;
    protected $table = 'tax';
    protected $fillable = [
        'name',
        'value',
        'organization_id',
        'updated_at',
        'created_by',
        'updated_by',
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