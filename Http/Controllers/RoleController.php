<?php

namespace  App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;


class RoleController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view role', ['only' => ['index']]);
        $this->middleware('permission:create role', ['only' => ['create','store','addPermissionToRole','givePermissionToRole']]);
       $this-> middleware('permission:update role', ['only' => ['update','edit']]);
        $this->middleware('permission:delete role', ['only' => ['destroy']]);
    }

    public function index()
    {
        $roles = Role::where('organization_id', org_id())->get();
        return view('pages.role-permission.role.index', ['roles' => $roles]);
    }

    public function create()
    {
        return view('pages.role-permission.role.create');
    }

    public function store(Request $request)
    {
        $organizationId = org_id(); // Fetch the current organization ID
        $roleName = $request->name; // Get the role name from the request

        // Validate that the name is required and a string
        $request->validate([
            'name' => 'required|string',
        ]);

        // Check for uniqueness of role name within the same organization
        $existingRole = Role::where('name', $roleName)
            ->where('organization_id', $organizationId)
            ->first();

        // If an existing role is found, return an error message
        if ($existingRole) {
            return redirect()->back()->withErrors(['name' => 'A role with this name already exists in your organization.']);
        }

        // Try creating the role using Eloquent
        try {
            $role = new Role();
            $role->name = $roleName;
            $role->organization_id = $organizationId;
            $role->created_by = Auth::id();
            $role->guard_name = 'web'; // Ensure this is set as needed
            $role->save(); // Attempt to save the role

            return redirect('roles')->with('status', 'Role Created Successfully');
        } catch (\Exception $e) {
            // Dump the exception message for debugging
            dd('Error Creating Role: ' . $e->getMessage());
        }
    }


    public function edit(Role $role)
    {
        return view('pages.role-permission.role.edit',[
            'role' => $role
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $request->validate([
            'name' => [
                'required',
                'string',
                'unique:roles,name,'.$role->id
            ]
        ]);

        $role->update([
            'name' => $request->name
        ]);

        return redirect('roles')->with('status','Role Updated Successfully');
    }

    public function destroy($roleId)
    {
        $role = Role::find($roleId);
        $role->delete();
        return redirect('roles')->with('status','Role Deleted Successfully');
    }

    public function addPermissionToRole($roleId)
    {

        $decryptedId = Crypt::decryptString($roleId);



        $permissions = Permission::get();
        $role = Role::findOrFail($decryptedId);
        $rolePermissions = DB::table('role_has_permissions')
            ->where('role_has_permissions.role_id', $role->id)
            ->pluck('role_has_permissions.permission_id','role_has_permissions.permission_id')
            ->all();

        return view('pages.role-permission.role.add-permissions', [
            'role' => $role,
            'permissions' => $permissions,
            'rolePermissions' => $rolePermissions
        ]);
    }
//    public function givePermissionToRole(Request $request, $roleId)
//    {
//        $request->validate([
//            'permission' => 'required|array', // Ensure permissions are an array
//            'permission.*' => 'exists:permissions,name', // Validate each permission name
//        ]);
//
//        $role = Role::findOrFail($roleId);
//
//        // Retrieve the current organization_id and the authenticated user ID
//        $organizationId = org_id();
////        $createdBy = Auth::id();
//        $createdBy='1';
//        // Fetch all existing permissions for the role and organization
//        $existingPermissions = DB::table('role_has_permissions')
//            ->where('role_id', $role->id)
//            ->where('organization_id', $organizationId)
//            ->pluck('permission_id')
//            ->toArray();
//
//        // Insert new permissions
//        foreach ($request->permission as $permissionName) {
//            $permission = Permission::where('name', $permissionName)->first();
//
//            if ($permission && !in_array($permission->id, $existingPermissions)) {
//                DB::table('role_has_permissions')->insert([
//                    'role_id' => $role->id,
//                    'permission_id' => $permission->id,
//                    'organization_id' => $organizationId, // new column
//                    'created_by' => $createdBy, // new column
//                ]);
//            }
//        }
//
//        return redirect()->back()->with('status', 'Permissions added to role');
//    }

    public function givePermissionToRole(Request $request, $roleId)
    {
//        $request->validate([
//            'permission' => 'required'
//        ]);
        $request->validate([
            'permission' => 'required|array', // Ensure permissions are an array
            'permission.*' => 'exists:permissions,name', // Validate each permission name
        ]);
//         $decryptedId = Crypt::decryptString($roleId);

        $role = Role::findOrFail($roleId);
        $role->syncPermissions($request->permission);

        return redirect()->back()->with('status','Permissions added to role');
    }
}
