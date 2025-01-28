<?php
//
//namespace App\Http\Controllers;
//
//use App\ChartOfAccounts;
//use App\Expense;
//use App\Industry;
//use App\PaymentReceived;
//use App\user;
//use App\Role;
//use App\Partner;
//use App\Transaction;
////use App\TransactionProject;
//use Illuminate\Http\Request;
//use Illuminate\Support\Facades\Crypt;
//use Illuminate\Support\Facades\DB;
//use Illuminate\Support\Facades\Hash;
//
//class UserController extends Controller
//{
//
//    public function store(Request $request)
//    {
//        $request->validate([
//            'firstname' => 'required|string|max:255',
//            'lastname' => 'required|string|max:255',
//            'username' => 'required|string|max:255|unique:users',
//            'email' => 'required|string|email|max:255|unique:users',
//            'password' => 'required|string|min:8',
//            'role_id' => 'required|int',
//        ], [
//            'username.unique' => 'The username has already been taken.',
//            'email.unique' => 'The email address has already been used.',
//            'password.min' => 'The password must be at least 8 characters long.', // Custom error message for password length
//
//        ]);
//
//        $user = new User([
//            'firstname' => $request->input('firstname'),
//            'lastname' => $request->input('lastname'),
//            'username' => $request->input('username'),
//            'email' => $request->input('email'),
//            'password' => Hash::make($request->input('password')),
//            'organization_id' => $request->input('organization_id'),
//            'created_by' => $request->input('created_by'),
//            'updated_by' => $request->input('updated_by'),
//            'role_id' => $request->input('role_id'),
//        ]);
//
//        $user->save();
//
//        return redirect()->route('UserController.index')->with('success', 'User created successfully.');
//    }
//    public function create()
//    {
//        $user = User::where('deleted', 0);
//         $currentUserCount = 0;
//        $counter = 0;
//         $userCount = 1;
//
//        $roles = Role::all();
//        return view('pages.user.create', compact([ 'user','userCount','roles']));
//    }
//    public function index()
//    {
//        $organizationId = 1;
//
//        $roles = Role::all();
//        $users = User::where('deleted', 0)->get();
//
//        return view('pages.user.index', compact([ 'users','roles']));
//    }
//    public function updateAccountStatus(Request $request, $id)
//    {
//        $userac = User::findOrFail($id);
//        $userac->is_activate = $request->input('active');
////        $account->updated_by = Auth::id();
//        $userac->updated_by = 2;
//        $userac->save();
//
//        return response()->json(['message' => 'success']);
//    }
//    public function deleteUser($id)
//    {
//        $user = User::findOrFail($id);
//        $user->deleted = 1;
//        $user->save();
//        return response()->json(['message' => 'success']);
//    }
//    public function edit($encryptedId)
//    {
//        $roles = Role::all();
//        $id = Crypt::decryptString($encryptedId);
//        $user = User::findOrFail($id);
////        $receivables = Partner::where('deleted', 0)
////            ->where('organization_id', org_id())
////            ->where('partner_type', 2)
////            ->get();
//
//        return view('pages.user.edit', compact(['user','roles']));
//    }
//    public function update(Request $request, $encryptedId)
//    {
//        // Decrypt the user ID
//       $id = Crypt::decryptString($encryptedId);
//       //var_dump('Decrypted ID:', $id);
////        // Find the user by ID or fail
////
////
////        // Validate the request data
//        $validatedData = $request->validate([
//            'firstname' => 'required|string|max:255',
//            'lastname' => 'required|string|max:255',
//            'username' => 'required|string|max:255|unique:users,username,' . $id,
//            'email' => 'required|string|email|max:255|unique:users,email,' . $id,
//           // 'password' => 'required|string|min:8|confirmed',
//            'role_id' => 'required|int',
//        ]);
//        $user = User::findOrFail($id);
//        $user->update($validatedData);
//        // Update the user's information
//        $user->firstname = $validatedData['firstname'];
//        $user->lastname = $validatedData['lastname'];
//        $user->username = $validatedData['username'];
//        $user->email = $validatedData['email'];
//
//        // Check if the password is different and needs to be hashed
////        if (!Hash::check($validatedData['password'], $user->password)) {
////            $user->password = Hash::make($validatedData['password']);
////        }
//////
//        $user->role_id = $validatedData['role_id'];
//        $user->save();
//
//        // Redirect back with a success message
//
//        $roles = Role::all();
//        $users = User::where('deleted', 0)->get();
//
//////            ->get();
//        return view('pages.user.index', compact([ 'users','roles']));
//      //return redirect()->back()->with('success', 'User updated successfully.');
//    }
//
//
//
//
//}


namespace App\Http\Controllers;

use  App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('check.permission:view users', ['only' => ['index']]);
        $this->middleware('check.permission:create user', ['only' => ['create', 'store']]);

        $this->middleware('check.permission:edit user', ['only' => ['update', 'edit','updateAccountStatus']]);
        $this->middleware('check.permission:delete user', ['only' => ['destroy','deleteUser']]);
    }

    public function index()
    {

        $users = User::where('deleted', 0)
            ->where('organization_id', org_id())
            ->get();
       // return view('pages.user.index', compact([ 'users']));
        return view('pages.role-permission.user.index', compact([ 'users']));
    }


    public function create()
    {
        $roles = Role::where('organization_id', orgID())->get();
        return view('pages.role-permission.user.create', ['roles' => $roles]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|max:20',
            'roles' => 'required'
        ]);

        $user = User::create([
            'username' => $request->email,
            'firstname' => $request->firstname,
            'lastname' => $request->lastname,
            'email' => $request->email,
            'role_id' => $request->roles,
            'password' => Hash::make($request->password),
            'organization_id' => org_id(),
            'created_by' => Auth::id(),
        ]);

//        $user->syncRoles($request->roles);

        return redirect('/users')->with('status', 'User created successfully with roles');
    }

    public function edit($id)
    {
        $id = Crypt::decryptString($id);
        $user = User::find($id);
        $roles = Role::where('organization_id', orgID())->get();
        return view('pages.role-permission.user.edit', [
            'user' => $user,
            'roles' => $roles
        ]);
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'lastname' => 'required|string|max:255',
            'firstname' => 'required|string|max:255',
//            'username' => 'required|string|max:255',
            'password' => 'nullable|string|min:8|max:20',
            'roles' => 'required'
        ]);

        $data = [
//            'username' => $request->username,
            'firstname' => $request->firstname,
            'lastname' => $request->lastname,
            'name' => $request->name,
            'email' => $request->email,
            'roles' => $request->roles,
        ];

//        if (!empty($request->password)) {
//            $data += [
//                'password' => Hash::make($request->password),
//            ];
//        }

        $user->update($data);
//        $user->syncRoles($request->roles);

        return redirect('/users')->with('status', 'User Updated Successfully with roles');
    }

    public function destroy($userId)
    {
        $user = User::find($userId->id);


            $user->delete();
            return response()->json(['status' => 'success', 'message' => 'Deleted Successfully']);


    }
    public function deleteUser($id)
    {
        $user = User::findOrFail($id);
        $user->deleted = 1;
        $user->save();
        return response()->json(['status' => 'success', 'message' => 'Deleted Successfully']);

//        return redirect('/users')->with('status', 'User Delete Successfully');
    }
        public function updateAccountStatus(Request $request, $id)
    {
        $userac = User::findOrFail($id);
        $userac->is_activate = $request->input('active');
        $userac->updated_by = Auth::id();
//        $userac->updated_by = 2;
        $userac->save();

            return redirect('/users')->with('status', 'User Delete Successfully');
    }

    public function login(Request $request)
    {

        $credentials = $request->only('email', 'password');

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();
            $user = Auth::user();

            $roleId = DB::table('model_has_roles')
                ->where('model_id', $user->id)
                ->value('role_id');

            if ($roleId == 1 && !$user->hasVerifiedEmail()) {
                return redirect()->route('verification.notice');
            }
            //        cashier
            if ($user && $user->role_id == 2 ) {
                return redirect()->route('cashier.index');
            }


            return redirect()->intended('/dashboard'); // redirect to your desired route
        }

        return redirect()->back()->withInput()->with('error', 'The provided credentials do not match our records.');
    }
}
