<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller {
    public function register(Request $request) {
        $validated = $request->validate([
            'name' => 'required|min:2',
            'email' => 'required|email|unique:users',
            'phone' => 'required|min:10',
            'password' => 'required|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'user' => $user->fresh(),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request) {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
                'errors' => ['email' => ['Invalid credentials']],
            ], 401);
        }

        $user->update(['last_sync_at' => now()]);
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request) {
        $request->user()->tokens()->delete();
        return response()->json(['success' => true]);
    }

    /** Issue a fresh token and revoke the one used for this request. */
    public function refresh(Request $request) {
        $user = $request->user();
        $current = $user->currentAccessToken();
        $token = $user->createToken('auth-token')->plainTextToken;
        if ($current && method_exists($current, 'delete')) {
            $current->delete();
        }

        return response()->json(['success' => true, 'user' => $user, 'token' => $token]);
    }

    public function getUser(Request $request) {
        return response()->json(['user' => $request->user()]);
    }
}
