<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class BankAccount extends Model
{
    use HasFactory;
    protected $table = 'bank_account';
    protected $fillable = [
        'account_type', 'bank_name', 'account_number', 'iban', 'swift', 'currency', 'employee_id', 'coa_id',
        'deleted', 'organization_id', 'created_at', 'created_by', 'updated_by'
    ];
    public function Employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
    public function Account()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'coa_id');
    }
    public function transactionDetails()
    {
        return $this->hasMany(TransactionDetails::class, 'account_id', 'coa_id');
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($data) {
            $data->organization_id = org_id();
            $data->created_by = Auth::id();
        });
          static::updating(function ($data) {
            $data->updated_by = Auth::id();
        });
    }
}