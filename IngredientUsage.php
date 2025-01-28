<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IngredientUsage extends Model
{
    use HasFactory;

    protected $fillable = ['ingredient_id', 'quantity_used'];

    public function ingredient()
    {
        return $this->belongsTo(ingredient::class);
    }
}
