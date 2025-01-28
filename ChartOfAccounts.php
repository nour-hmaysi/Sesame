<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChartOfAccounts extends Model
{
    use HasFactory;
    protected $table = 'chart_of_account';
    protected $fillable = [
        'type_id',
        'name',
        'code',
        'description',
        'opening_balance',
        'deleted',
        'organization_id',
        'status',
        'created_by',
    ];
    public function accountType()
    {
        return $this->belongsTo(AccountType::class, 'type_id');
    }
}
