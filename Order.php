<?php
namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('organization', function (Builder $builder) {
            $builder->where('organization_id', org_id()); // Ensure org_id() returns the current organization's ID
        });
    }
    protected $fillable = ['order_number', 'total_cost', 'status','organization_id','deliveryTax','subtotal','tax'];

    public function orderDetails()
    {
        return $this->hasMany(OrderDetail::class);
    }

}
