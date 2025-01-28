<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Budget extends Model
{
    use HasFactory;
    protected $table = 'budget_account';
    protected $fillable = [
        'account_id',
        'amount',
        'organization_id',
        'year',
        'created_by',
    ];
    public function Account()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'account_id');
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($account) {
            $account->organization_id = org_id();
            $account->created_by = Auth::id();
        });
    }
}