<?php
namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    protected static function boot()
{
    parent::boot();

    static::addGlobalScope('organization', function (Builder $builder) {
        $builder->where('organization_id', org_id()); // Ensure org_id() returns the current organization's ID
    });
}

    use HasFactory;

    protected $fillable = [
        'name', 'sku', 'ingredient_category_id',
        'barcode','organization_id'
    ];

    public function ingredientCategory()
    {
        return $this->belongsTo(IngredientCategory::class);
    }

    public function stocks()
    {
        return $this->hasMany(IngredientStock::class);
    }   // Define the relationship with IngredientStock
    public function ingredientStocks()
    {
        return $this->hasMany(IngredientStock::class, 'ingredient_id');
    }
    public function ingredientStocksDetails()
    {
        return $this->hasMany(IngredientStockDetails::class, 'ingredient_id');
    }


    public function ingredientUsages()
    {
        return $this->hasMany(IngredientUsage::class);
    }

    public function productIngredientOrders()
    {
        return $this->hasMany(ProductIngredientOrder::class);
    }
    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class, 'ingredient_id');
    }

}
