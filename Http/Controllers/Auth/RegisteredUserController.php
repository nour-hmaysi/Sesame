<?php

namespace App\Http\Controllers\Auth;

use App\ChartOfAccounts;
use App\DefaultChartOfAccounts;
use App\Http\Controllers\Controller;
use App\Organization;
use App\Providers\RouteServiceProvider;
use http\Client\Curl\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules;
use Illuminate\View\View;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request)
    {

        $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            'company_location' => ['required', 'string', 'max:255'],
            'mobile_number' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:'.\App\User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

//        create org
        $org = Organization::create([
            'name' => $request->company_name,
            'address' => $request->company_location,
            'industry_id' => $request->industry_id,
        ]);


        if($org){
            $orgID = $org->id;



//            create admin user
            $user = \App\User::create([
                'firstname' => $request->first_name,
                'lastname' => $request->last_name,
                'username' => $request->email,
                'organization_id' => $orgID,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

//            add user role
            DB::table('model_has_roles')->insert([
                'role_id' => 1,
                'model_type' => 'App\User',
                'model_id' => $user->id,
            ]);

            event(new Registered($user));

            Auth::login($user);

            //            create default chart of acc for org
            $defaultAccount = DefaultChartOfAccounts::get();
            foreach($defaultAccount as $account){

                ChartOfAccounts::create([
                    'type_id' => $account->type_id,
                    'name' => $account->name,
                    'description' => $account->description,
                    'is_default' => 1,
                    'organization_id' => $orgID,
                ]);
            }
            return redirect()->route('verification.notice');

//            return redirect()->intended(RouteServiceProvider::HOME);

//            return back()->with('status', 'verification-link-sent');

//            Mail::to($user->email)->send(new WelcomeEmail($user));
//            return redirect(RouteServiceProvider::HOME);


//            return redirect()->route('OrganizationController.edit', ['id' => Crypt::encryptString($orgID)])->with('success', 'Registration successful');
        }



    }
}
