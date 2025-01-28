<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Industry extends Model
{
    use HasFactory;

    protected $table = 'industry';

    protected $fillable = [
        'id', 'name'
    ];
}
