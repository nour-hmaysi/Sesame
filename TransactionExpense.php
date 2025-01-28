<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionExpense extends Model
{
    use HasFactory;
    protected $table = 'transaction_expense';
    protected $fillable = [
        'id',
        'transaction_id',
        'expense_id'
    ];
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
    public function expense()
    {
        return $this->belongsTo(Expense::class, 'expense_id');
    }
}
