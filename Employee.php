<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Employee extends Model
{
    use HasFactory;
    protected $table = 'employee';
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'note',
        'salary',
        'basic_housing',
        'other_allowance',
        'absence',
        'account_id',
        'date',
        'deleted',
        'organization_id',
        'created_by',
        'updated_by',
    ];
    protected static function boot()
    {
        parent::boot();
        static::creating(function ($partner) {
            $partner->created_by = Auth::id();
            $partner->organization_id = org_id();
        });
          static::updating(function ($data) {
            $data->updated_by = Auth::id();
        });
    }
}