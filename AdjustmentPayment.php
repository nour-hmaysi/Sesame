<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

//payment adjustment
class AdjustmentPayment extends Model
{
    use HasFactory;
    protected $table = 'adjustment_payment';
    protected $fillable = [
        'id',
        'type_id',
        'amount',
        'payment_id',
        'cn_id',
        'invoice_id',
        'organization_id',
        'date',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by'
    ];
    public function Payment()
    {
        return $this->hasMany(PaymentReceived::class, 'payment_id');
    }
    public function SalesInvoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }
    public function CreditNote()
    {
        return $this->belongsTo(CreditNote::class, 'cn_id');
    }
    public function Partner()
    {
        return $this->belongsTo(Partner::class, 'paid_by_id');
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($payment) {
            $payment->created_by = Auth::id();
            $payment->organization_id = org_id();
        });
          static::updating(function ($data) {
            $data->updated_by = Auth::id();
        });
    }
}