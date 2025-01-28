<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceFiles extends Model
{
    use HasFactory;
    protected $table = 'invoice_files';
    protected $fillable = [
        'invoice_id',
        'invoice_type_id',
        'name'
    ];

}
