<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class PurchaseInvoice extends Model
{
    use HasFactory;
    protected $table = 'purchase_invoice';
    protected $fillable = [
        'invoice_type',
        'invoice_number',
        'partner_id',
        'order_number',
        'invoice_date',
        'due_date',
        'supply_date',
        'terms',
        'subject',
        'project_id',
        'currency',
        'subtotal',
        'total',
        'original_amount',
        'taxable_amount',
        'non_taxable_amount',
        'amount_due',
        'amount_received',
        'discount_amount',
        'vat_amount',
        'is_tax',
        'terms_conditions',
        'notes',
        'po_id',
        'deleted',
        'organization_id',
        'updated_at',
        'created_by',
        'updated_by',
    ];
    public function items()
    {
        return $this->belongsToMany(Item::class, 'invoice_has_items')
            ->withPivot('tax', 'discount', 'account_id')
            ->withTimestamps();
    }
    public function Currency()
    {
        return $this->belongsTo(Currency::class, 'currency');
    }
    public function Partner()
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($invoice) {
            $invoice->amount_due = $invoice->total;
            $invoice->currency = currencyID();
            $invoice->status = 1;
            $invoice->organization_id = org_id();
            $invoice->created_by = Auth::id();
        });
          static::updating(function ($data) {
            $data->updated_by = Auth::id();
        });
    }
    public static function generateInvoiceNumber()
    {
        $latestInvoice = self::where('organization_id', org_id())
            ->orderBy('id', 'desc')
            ->first();
        if ($latestInvoice) {
            $newInvoiceNumber = $latestInvoice->invoice_number + 1;
        }else{
            $newInvoiceNumber = 1;
        }
        return  $newInvoiceNumber;
    }
}
