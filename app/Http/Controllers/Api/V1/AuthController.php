<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\PasswordUpdateRequest;
use App\Http\Requests\Api\V1\ProfileUpdateRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Email\EmailMarketingService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, EmailMarketingService $emailMarketing): JsonResponse
    {
        $validated = $request->validated();

        $user = User::query()->create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'name' => $validated['first_name'].' '.$validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'company_name' => $validated['company_name'] ?? null,
            'vat_number' => $validated['vat_number'] ?? null,
            'password' => $validated['password'],
            'is_active' => true,
        ]);

        $user->assignRole(filled($user->company_name) ? 'b2b_customer' : 'customer');
        $user->profile()->create();
        $emailMarketing->welcome($user);

        return response()->json([
            'data' => [
                'token' => $user->createToken('frontend')->plainTextToken,
                'user' => UserResource::make($user->load('profile')),
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'This account is inactive.',
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $user->profile()->firstOrCreate([]);

        return response()->json([
            'data' => [
                'token' => $user->createToken('frontend')->plainTextToken,
                'user' => UserResource::make($user->load('profile')),
            ],
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        Password::sendResetLink($validated);

        return response()->json([
            'message' => 'If the email exists, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ]);

        $status = Password::reset($validated, function (User $user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => UserResource::make($request->user()->load('profile')),
        ]);
    }

    public function updateProfile(ProfileUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $userFields = collect($validated)->only([
            'first_name',
            'last_name',
            'phone',
            'company_name',
            'vat_number',
        ])->all();

        if (isset($userFields['first_name']) || isset($userFields['last_name'])) {
            $userFields['name'] = trim(($userFields['first_name'] ?? $user->first_name).' '.($userFields['last_name'] ?? $user->last_name));
        }

        $user->update($userFields);
        $user->profile()->updateOrCreate([], collect($validated)->only([
            'avatar',
            'birthday',
            'newsletter_subscribed',
            'preferences',
        ])->all());

        return response()->json([
            'data' => UserResource::make($user->refresh()->load('profile')),
        ]);
    }

    public function updatePassword(PasswordUpdateRequest $request): JsonResponse
    {
        $request->user()->update([
            'password' => $request->validated('password'),
        ]);

        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Password updated. Please log in again.']);
    }
}
