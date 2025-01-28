<?php

namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IngredientCategory extends Model
{
    use HasFactory;
    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('organization', function (Builder $builder) {
            $builder->where('organization_id', org_id()); // Ensure org_id() returns the current organization's ID
        });
        // Prevent deletion if related ingredients exist
        static::deleting(function ($category) {
            if ($category->ingredients()->exists()) {
                throw new \Exception("Cannot delete category because it is related to ingredients.");
            }
        });
    }
    protected $fillable = ['name',
        'organization_id'];

    public function ingredients()
    {
        return $this->hasMany(Ingredient::class);
    }
}
