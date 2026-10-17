<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return response()->json(
            User::orderBy('name')->get(['id', 'name', 'email', 'role', 'active'])
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'role' => ['required', Rule::in(['admin', 'recepcionista'])],
        ]);

        $user = User::create($data + ['active' => true]);

        return response()->json($user->only(['id', 'name', 'email', 'role', 'active']), 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'role' => ['sometimes', Rule::in(['admin', 'recepcionista'])],
            'active' => ['sometimes', 'boolean'],
        ]);

        // Evita que el único administrador se bloquee a sí mismo
        $losesAdmin = ($data['active'] ?? true) === false
            || (isset($data['role']) && $data['role'] !== 'admin');

        if ($user->id === $request->user()->id && $losesAdmin) {
            return response()->json(['message' => 'No puedes desactivarte ni quitarte el rol de administrador.'], 409);
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        // Si se desactiva o cambia la contraseña, se cierran sus sesiones abiertas
        if (($data['active'] ?? true) === false || isset($data['password'])) {
            $user->tokens()->delete();
        }

        return response()->json($user->only(['id', 'name', 'email', 'role', 'active']));
    }
}
