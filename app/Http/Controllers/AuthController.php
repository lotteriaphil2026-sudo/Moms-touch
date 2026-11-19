<?php
namespace App\Http\Controllers;
use App\Models\{User, Wallet};
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{Auth, DB, Hash, Password};
use Illuminate\Support\Str;
use Illuminate\Validation\{Rules, ValidationException};
class AuthController extends Controller
{
    public function login(Request $request, PortalController $portal): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string', 'max:128']]);
        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect. Please try again.']);
        }
        $request->session()->regenerate();
        return $portal->state($request);
    }
    public function register(Request $request, PortalController $portal): JsonResponse
    {
        abort_unless(config('portal.registration_enabled'), 403, 'Registration is temporarily unavailable. Please check back soon.');
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'max:128', Rules\Password::min(8)],
        ]);
        $user = DB::transaction(function () use ($input) {
            $user = User::create(['name' => $input['name'], 'email' => $input['email'], 'password' => Hash::make($input['password'])]);
            Wallet::create(['user_id' => $user->id]);
            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();
        return $portal->state($request);
    }
    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['message' => 'Signed out.', 'csrf' => csrf_token()]);
    }
    public function forgot(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $request->validate(['email' => ['required', 'email', 'max:254']]);
        try { Password::sendResetLink($request->only('email')); }
        catch (\Throwable $exception) { report($exception); } // Keep the public response identical if delivery fails.
        return response()->json(['message' => 'If an account exists for this email, a reset link has been requested.']);
    }
    public function reset(Request $request)
    {
        $input = $request->validate([
            'token' => ['required'], 'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'max:128', Rules\Password::min(8)],
        ]);
        $status = Password::reset($input, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __($status)]);
        }
        return redirect('/#/login')->with('status', __($status));
    }
}
