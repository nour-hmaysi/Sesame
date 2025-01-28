<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ForgotPassController extends Controller
{
//    public function showLinkRequestForm()
//    {
//        return view('auth.forgot-password');
//    }

    public function sendResetLinkEmail(Request $request)
    {
        $request->validate(['email' => 'required|email|exists:users,email']);

        $response = Password::sendResetLink($request->only('email'));

        return $response == Password::RESET_LINK_SENT
            ? back()->with('status', __('Password reset link sent!'))
            : back()->withErrors(['email' => __('Failed to send reset link.')]);
    }
}
