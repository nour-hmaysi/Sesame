<?php
namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderCustomNote extends Model
{
    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('organization', function (Builder $builder) {
            $builder->where('organization_id', org_id()); // Ensure org_id() returns the current organization's ID
        });
    }
    protected $fillable = ['order_detail_id', 'note','organization_id'];

    public function orderDetail()
    {
        return $this->belongsTo(OrderDetail::class);
    }
}
