<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionAsset extends Model
{
    use HasFactory;
    protected $table = 'transaction_asset';
    protected $fillable = [
        'id',
        'transaction_id',
        'asset_id'
    ];
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
    public function asset()
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
