<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionDocuments extends Model
{
    use HasFactory;
    protected $table = 'transaction_document';
    protected $fillable = [
        'id',
        'transaction_id',
        'name'
    ];
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
}
