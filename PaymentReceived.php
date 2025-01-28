<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

//payment record
class PaymentReceived extends Model
{
    use HasFactory;
    protected $table = 'payment_received';
    protected $fillable = [
        'id',
        'type_id',
        'amount',
        'bank_charge',
        'unused_amount',
        'payment_number',
        'reference_number',
        'mode',
        'currency',
        'note',
        'paid_by_id',
        'organization_id',
        'date',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by'
    ];
    public function Transaction()
    {
        return $this->hasMany(Transaction::class, 'payment_id')->orderBy('transaction_type_id');
    }
    public function TransactionType()
    {
        return $this->belongsTo(TransactionType::class, 'type_id');
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