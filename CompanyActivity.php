<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CompanyActivity extends Model
{
    use HasFactory;
    protected $table = 'company_activity';
    protected $fillable = [
        'id',
        'name',
    ];
}
