<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionTax extends Model
{
    use HasFactory;
    protected $table = 'transaction_vat';
    protected $fillable = [
        'id',
        'transaction_id',
        'vat_report_id'
    ];
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
}
