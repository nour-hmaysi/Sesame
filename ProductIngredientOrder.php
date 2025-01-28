<?php
namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductIngredientOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id', 'ingredient_id', 'is_default', 'is_optional', 'cost'
    ];

    // Define relationships here if necessary
}
