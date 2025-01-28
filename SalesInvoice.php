<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

use function Symfony\Component\String\length;

class SalesInvoice extends Model
{
    use HasFactory;
    protected $table = 'sales_invoice';
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
        'retention_amount',
        'adv_amount',
        'vat_amount',
        'retention_pct',
        'adv_pct',
        'is_tax',
        'terms_conditions',
        'notes',
        'file',
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
    public function transactionInvoices()
    {
        return $this->hasMany(TransactionInvoice::class, 'invoice_id');
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
        $invFormat = InvFormat();
        $prefix = $invFormat['prefix'] ?? 'INV';
        $startingNumber = $invFormat['start_nb'] ?? '1';
        $digit = $invFormat['digit'] ?? 1;

        $latestInvoice = self::where('organization_id', org_id())
            ->orderBy('id', 'desc')
            ->first();
        if (!$latestInvoice) {
            $newInvoiceNumber = $prefix . str_pad($startingNumber, $digit, '0', STR_PAD_LEFT);
        } else {
            $numericPart = intval(substr($latestInvoice->invoice_number, strlen($prefix)));
            $nextNumber = $numericPart + 1;
            $nextNbDigit = strlen((string) $nextNumber);
            $newInvoiceNumber = $prefix . str_pad($nextNumber, $digit, '0', STR_PAD_LEFT);
        }
        return  $newInvoiceNumber;
    }

}
