<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceHasItems extends Model
{
    use HasFactory;
    protected $table = 'invoice_items';
    protected $fillable = [
        'invoice_id',
        'invoice_type_id',
        'item_id',
        'item_name',
        'item_description',
        'quantity',
        'unit',
        'rate',
        'sale_price',
        'final_amount',
        'amount',
        'tax',
        'discount',
        'coa_id',
        'created_at',
        'updated_at'
    ];
    public function account()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'coa_id');
    }
}
