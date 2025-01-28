<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DefaultChartOfAccounts extends Model
{
    use HasFactory;
    protected $table = 'default_chart_of_account';
    protected $fillable = [
        'type_id',
        'name',
        'description'
    ];
}
