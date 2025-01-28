<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Organization extends Model
{
    use HasFactory;

    protected $table = 'organizationn';

    protected $fillable = [
        'logo', 'address','industry_id', 'infos', 'country_region', 'additional_number', 'district',
        'city', 'state', 'zip_code', 'phone', 'fax_number', 'color_layout', 'invoice_prefix', 'inv_start_nb', 'inv_digit', 'currency_id',
        'organization_email', 'vat_rate','vat_number','name'
    ];
}
