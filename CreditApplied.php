<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditApplied extends Model
{
    use HasFactory;
    protected $table = 'credit_applied';
    protected $fillable = [
        'credit_id',
        'invoice_id',
        'date',
        'amount',
        'ob_id',
        'is_creditnote',
        'organization_id',
        'updated_at'
    ];
    public function CreditNote()
    {
        return $this->belongsTo(CreditNote::class, 'credit_id');
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($account) {
            $account->organization_id = org_id();
        });
    }
}
