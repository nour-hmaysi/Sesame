<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountType extends Model
{
    use HasFactory;
    protected $table = 'account_type';
    protected $fillable = [
        'id',
        'name',
        'hidden',
        'parent_id',
        'cat_id',
        'report_name',
        'is_subtype',
        'is_cat'
    ];
    public function parent()
    {
        return $this->belongsTo(AccountType::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(AccountType::class, 'parent_id');
    }

    public function category()
    {
        return $this->belongsTo(AccountType::class, 'cat_id');
    }


    public function accounts()
    {
        return $this->hasMany(ChartOfAccounts::class, 'type_id');
    }
}
