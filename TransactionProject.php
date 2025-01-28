<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionProject extends Model
{
    use HasFactory;
    protected $table = 'transaction_project';
    protected $fillable = [
        'id',
        'transaction_id',
        'project_id'
    ];
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
