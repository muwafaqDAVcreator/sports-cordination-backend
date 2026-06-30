<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller {
    public function show(Request $request) {
        return response()->json(['success' => true, 'data' => $request->user()], 200);
    }

    public function update(Request $request) {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|string|min:2',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'phone' => 'sometimes|min:10',
            'house' => 'nullable|string',
        ]);

        $user->update($validated);

        return response()->json(['success' => true, 'data' => $user], 200);
    }

    public function changePassword(Request $request) {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect'],
            ]);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        return response()->json(['success' => true, 'message' => 'Password changed successfully'], 200);
    }
}
