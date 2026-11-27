<?php
namespace App\Http\Controllers;
use App\Models\{User, Wallet};
use App\Services\{InvestmentReminderService, MemberSignInRecorder, WebPushNotificationService};
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{Auth, DB, Hash, Password};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
        $user = $request->user();
        if ($user instanceof User && !$user->is_admin) {
            app(MemberSignInRecorder::class)->record($request, $user);
        }
        $request->session()->regenerate();
        if ($user instanceof User && ! $user->is_admin) {
            app(InvestmentReminderService::class)->sendIfEligible($user);
        }

        return $portal->state($request);
    }
    public function register(Request $request, PortalController $portal): JsonResponse
    {
        abort_unless(config('portal.registration_enabled'), 403, 'Registration is temporarily unavailable. Please check back soon.');
        $referralCode = Str::upper(trim((string) $request->input('referral_code')));
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
            'phone' => trim((string) $request->input('phone')),
            'address' => trim((string) $request->input('address')),
            'referral_code' => $referralCode === '' ? null : $referralCode,
        ]);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'referral_code' => ['required', 'string', 'max:80', 'exists:users,referral_code'],
            'phone' => [
                'required',
                'string',
                'max:30',
                'regex:/\A\+?[0-9][0-9()\s.-]{5,28}\z/',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    $digits = preg_match_all('/[0-9]/', (string) $value);
                    if ($digits === false || $digits < 7 || $digits > 15) {
                        $fail('Enter a phone number with 7 to 15 digits.');
                    }
                },
            ],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'address' => ['required', 'string', 'max:500'],
            'gender' => ['required', Rule::in(['Male', 'Female', 'Other', 'Prefer not to say'])],
            'marital_status' => ['required', Rule::in(['Single', 'Married', 'Widowed', 'Separated'])],
            'password' => ['required', 'confirmed', 'max:128', Rules\Password::min(8)],
        ]);
        $referrer = isset($input['referral_code'])
            ? User::where('referral_code', $input['referral_code'])->firstOrFail()
            : null;
        $user = DB::transaction(function () use ($input, $referrer) {
            $user = new User([
                'name' => $input['name'],
                'email' => $input['email'],
                'phone' => trim($input['phone']),
                'date_of_birth' => $input['date_of_birth'],
                'address' => $input['address'],
                'gender' => $input['gender'],
                'marital_status' => $input['marital_status'],
                'password' => Hash::make($input['password']),
            ]);
            if ($referrer) {
                $user->referrer()->associate($referrer);
            }
            $user->save();
            Wallet::create(['user_id' => $user->id]);
            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();
        app(MemberSignInRecorder::class)->record($request, $user);
        app(WebPushNotificationService::class)->notifyAdmins(
            'A new member has created an account. Open the member directory to view the account.',
            '/#/admin?tab=members',
            'New user registered'
        );

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
