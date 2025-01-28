<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseDocuments extends Model
{
    use HasFactory;
    protected $table = 'expense_document';
    protected $fillable = [
        'id',
        'expense_id',
        'name'
    ];
    public function Expense()
    {
        return $this->belongsTo(Expense::class, 'expense_id');
    }
}
