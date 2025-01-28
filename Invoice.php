<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;
    protected $table = 'invoices';
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
        'subtotal',
        'total',
        'is_tax',
        'terms_conditions',
        'notes',
        'file',
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
}
