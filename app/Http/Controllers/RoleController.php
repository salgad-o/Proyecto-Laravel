<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateRoleRequest;
use App\Models\Role;
use Illuminate\Support\Facades\Gate;

class RoleController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', Role::class);

        $roles = Role::withCount('users')
            ->orderBy('id')
            ->get();

        return view('roles.index', [
            'roles' => $roles,
            'permisos' => config('roles'),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role)
    {
        Gate::authorize('update', $role);

        // validated() solo trae label y description: name e id se ignoran.
        $role->update($request->validated());

        return back()->with('status', 'Rol actualizado.');
    }
}
