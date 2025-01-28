<?php

namespace App;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Expense extends Model
{
    use HasFactory;
    protected $table = 'expense';
    protected $fillable = [
        'expense_type', 'expense_account_id', 'project_id', 'customer_id', 'payment_account_id', 'vat', 'vat_number', 'invoice_number',
        'reference', 'details', 'starting_date', 'ending_date', 'repetitive', 'date', 'recurring_date','amount','vat_amount', 'related_to', 'refunded_amount', 'organization_id',
        'created_by', 'updated_by'
    ];
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
    public function account()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'expense_account_id');
    }
    public function paymentAccount()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'payment_account_id');
    }
    public function Vat()
    {
        return $this->belongsTo(Tax::class, 'vat');
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($expense) {
            $expense->recurring_date = Carbon::parse($expense->starting_date)->addMonths($expense->repetitive);
            $expense->created_by = Auth::id();
            $expense->organization_id = org_id();
        });
          static::updating(function ($data) {
            $data->updated_by = Auth::id();
        });
    }
}
