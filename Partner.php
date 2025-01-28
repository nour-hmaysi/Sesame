<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Partner extends Model
{
    use HasFactory;
    protected $table = 'partner';
    protected $fillable = [
        'type',
        'partner_type',
        'salutation',
        'firstname',
        'lastname',
        'ar_salutation',
        'ar_firstname',
        'ar_lastname',
        'company_name',
        'display_name',
        'ar_display_name',
        'email',
        'phone',
        'additional_phone',
        'tax_treatment',
        'tax_number',
        'cr_number',
        'company_activity',
        'place',
        'location',
        'attention',
        'country',
        'address',
        'district',
        'city',
        'state',
        'building_number',
        'ar_building_number',
        'ar_attention',
        'ar_country',
        'ar_address',
        'ar_district',
        'ar_city',
        'ar_state',
        'zip_code',
        'address_phone',
        'fax_number',
        'currency',
        'opening_balance',
        'payment_terms',
        'remarks',
        'deleted',
        'organization_id',
        'created_by',
        'updated_by',
    ];
//    protected static function boot()
//    {
//        parent::boot();
//
//        static::deleting(function ($partner) {
//            $partner->contacts()->delete();
//        });
//    }

    public function PaymentTerms()
    {
        return $this->belongsTo(PaymentTerms::class, 'payment_terms');
    }
    public function Currency()
    {
        return $this->belongsTo(Currency::class, 'currency');
    }
    public function contacts()
    {
        return $this->hasMany(PartnersContactPerson::class);
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($partner) {
            $partner->created_by = Auth::id();
            $partner->organization_id = org_id();
        });
          static::updating(function ($data) {
            $data->updated_by = Auth::id();
        });
    }
}