<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController
{
    /**
     * Bejelentkezés és token generálása
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !password_verify($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['A bejelentkezési adatok helytelenek.'],
            ]);
        }

        $token = $user->createToken('admin-token', ['admin:access'])->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'message' => 'Sikeres bejelentkezés!',
        ]);
    }

    /**
     * Kijelentkezés
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sikeresen kijelentkeztél.']);
    }

    /**
     * Aktuális user adatai
     */
    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}
