<?php

namespace App;



use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsageUnit extends Model
{
    use HasFactory;
    protected $table = 'usage_unit';
    protected $fillable = ['id',
        'name'];

}
