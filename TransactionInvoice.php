<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionInvoice extends Model
{
    use HasFactory;
    protected $table = 'transaction_invoice';
    protected $fillable = [
        'id',
        'transaction_id',
        'transaction_details_id',
        'invoice_id',
        'invoice_type_id'
    ];
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
    public function SalesInvoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }
    public function CreditNote()
    {
        return $this->belongsTo(CreditNote::class, 'invoice_id');
    }
    public function PurchaseInvoice()
    {
        return $this->belongsTo(PurchaseInvoice::class, 'invoice_id');
    }
    public function DebitNote()
    {
        return $this->belongsTo(DebitNote::class, 'invoice_id');
    }
    public function transactionDetails()
    {
        return $this->belongsTo(TransactionDetails::class, 'transaction_details_id');
    }
}
