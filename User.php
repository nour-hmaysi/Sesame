<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;
//use spatie\Permission\Traits\HasRoles;
class User extends  Model
{


    use HasFactory, HasRoles;
    protected $table = 'users';
    protected $fillable = [
         'email',
        'email_verified_at',
        'password',
        'deleted',
        'remember_token',
        'created_at',
        'updated_at',
        'firstname',
        'lastname',
        'username',
        'role_id',
        'organization_id',
        'is_activate',
        'created_by',
        'updated_by',
    ];


}
