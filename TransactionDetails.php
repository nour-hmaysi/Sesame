<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class TransactionDetails extends Model
{
    use HasFactory;
    protected $table = 'transaction_details';
    protected $fillable = [
        'id',
        'transaction_id',
        'account_id',
        'amount',
        'is_debit	',
        'description	',
        'paid_by_id	',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by'
    ];
    public function account()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'account_id');
    }
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            $transaction->created_by = Auth::id();
        });
          static::updating(function ($data) {
            $data->updated_by = Auth::id();
        });
    }
}