<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionAdjustment extends Model
{
    use HasFactory;
    protected $table = 'transaction_adjustment';
    protected $fillable = [
        'id',
        'transaction_id',
        'ob_id',
        'is_account',
        'is_debit',
    ];
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
}
