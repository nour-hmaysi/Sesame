<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PartnersContactPerson extends Model
{
    use HasFactory;
    protected $table = 'partners_contact_persons';
    protected $fillable = [
        'partner_id',
        'salutation',
        'firstname',
        'lastname',
        'email',
        'phone',
        'created_by',
        'updated_by',
    ];
    public function partners()
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }
}
