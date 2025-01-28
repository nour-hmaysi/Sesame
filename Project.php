<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Project extends Model
{
    use HasFactory;
    protected $table = 'project';
    protected $fillable = [
        'name',
        'code',
        'description',
        'date',
        'project_cost',
        'adv_amount',
        'retention_amount',
        'reference_number',
        'deleted',
        'receivable_id',
        'organization_id',
        'created_by',
        'updated_by',
    ];
    public function customer()
    {
        return $this->belongsTo(Partner::class, 'receivable_id');
    }

    public function Partner()
    {
        return $this->belongsTo(Partner::class, 'receivable_id');
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($partner) {
            $partner->created_by = Auth::id();
            $partner->organization_id = org_id();
        });
          static::updating(function ($data) {
            $data->updated_by = Auth::id();
        });
    }

    public static function generateAutoNumber()
    {
        $latestInvoice = self::where('organization_id', org_id())
            ->orderBy('id', 'desc')
            ->first();
        if ($latestInvoice) {
            $newNumber = $latestInvoice->code + 1;
        }else{
            $newNumber = 1;
        }
        return  $newNumber;
    }
}
