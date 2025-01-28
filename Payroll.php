<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Payroll extends Model
{
    use HasFactory;
    protected $table = 'payroll';
    protected $fillable = [
        'employee_id',
        'date',
        'salary',
        'reference_number',
        'basic_housing',
        'other_allowance',
        'absence',
        'project_id',
        'transaction_id',
        'organization_id',
        'created_by',
        'updated_by',
    ];

    public function Employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
    public function Project()
    {
        return $this->belongsTo(Project::class, 'project_id');
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
}
