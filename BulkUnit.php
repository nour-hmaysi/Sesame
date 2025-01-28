<?php

namespace App;



use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BulkUnit extends Model
{
    use HasFactory;
    protected $table = 'bulk_unit';
    protected $fillable = ['id',
        'name'];

}
