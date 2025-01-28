<?php

namespace App;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DebitApplied extends Model
{
    use HasFactory;
    protected $table = 'debit_applied';
    protected $fillable = [
        'debit_id',
        'invoice_id',
        'date',
        'amount',
        'ob_id',
        'is_creditnote',
        'organization_id',
        'updated_at'
    ];
    public function DebitNote()
    {
        return $this->belongsTo(DebitNote::class, 'debit_id');
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($account) {
            $account->organization_id = org_id();
        });
    }
}
