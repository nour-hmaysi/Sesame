<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Comment extends Model
{
    use HasFactory;
    protected $table = 'comments_history';
    protected $fillable = [
        'id',
        'type_id',
        'related_to_id',
        'text',
        'is_comment',
        'organization_id',
        'created_by',
        'updated_at',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($commment) {
            $commment->created_by = Auth::id();
            $commment->organization_id = org_id();
        });
    }
}