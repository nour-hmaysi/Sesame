<?php
// app/Http/Middleware/CheckPermission.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class CheckPermission
{
    public function handle($request, Closure $next, $permission)
    {

        $user = Auth::user();

        // Check if the user is authenticated and has the required permission
        if (!$user || !$user->can($permission)) {
            // Set a session flash message
            Session::flash('alert', 'You do not have the right permissions to perform this action.');

            // Redirect the user back
            return redirect()->back()->with('error', 'You do not have the right permissions to perform this action.');
        }


        return $next($request);
    }
}
