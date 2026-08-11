<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Admin/Users/Index', [
            'users' => User::query()
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'email', 'created_at'])
                ->map(fn(User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'created_at' => $user->created_at?->toISOString(),
                    'is_current' => $user->is($request->user()),
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);

        $user = User::create($data);

        AuditLog::log('user.created', $user, [
            'name' => $user->name,
            'email' => $user->email,
        ]);

        return back()->with('success', "Administrator {$user->email} created.");
    }

    public function updatePassword(Request $request, User $user)
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);

        $user->update(['password' => $data['password']]);

        AuditLog::log('user.password_changed', $user, [
            'changed_by_user_id' => $request->user()->id,
        ]);

        return back()->with('success', "Password updated for {$user->email}.");
    }
}
