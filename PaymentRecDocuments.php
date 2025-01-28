<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentRecDocuments extends Model
{
    use HasFactory;
    protected $table = 'payment_received_documents';
    protected $fillable = [
        'id',
        'payment_id',
        'name'
    ];
    public function payment()
    {
        return $this->belongsTo(PaymentReceived::class, 'payment_id');
    }
}
