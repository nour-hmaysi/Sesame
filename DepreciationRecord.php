<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DepreciationRecord extends Model
{
    use HasFactory;
    protected $table = 'depreciation_records';
    protected $fillable = [
        'asset_id',
        'depreciation_date',
        'depreciation_amount',
        'accumulated_depreciation',
        'book_value',
        'next_date',
        'organization_id',
        'created_by',
        'updated_by',
    ];
    public function Asset()
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($dep) {
            $dep->organization_id = org_id();
        });
    }
}
