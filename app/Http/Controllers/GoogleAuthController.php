<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Wallet;
use App\Services\InvestmentReminderService;
use App\Services\MemberSignInRecorder;
use App\Services\WebPushNotificationService;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->configured()) {
            return $this->oauthError($request, 'unavailable');
        }

        $request->session()->forget(['google_oauth_state', 'google_oauth_nonce', 'google_oauth_verifier', 'google_onboarding']);
        $state = Str::random(64);
        $nonce = Str::random(64);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $request->session()->put([
            'google_oauth_state' => $state,
            'google_oauth_nonce' => $nonce,
            'google_oauth_verifier' => $verifier,
            'google_signup_referral' => mb_substr(trim((string) $request->query('ref')), 0, 80),
        ]);

        $query = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'prompt' => 'select_account',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    public function callback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull('google_oauth_state');
        $expectedNonce = $request->session()->pull('google_oauth_nonce');
        $codeVerifier = $request->session()->pull('google_oauth_verifier');

        if (! is_string($expectedState) || ! is_string($request->query('state'))
            || ! hash_equals($expectedState, $request->query('state'))
            || ! is_string($codeVerifier)) {
            return $this->oauthError($request, 'invalid_state');
        }

        if ($request->query('error') !== null) {
            return $this->oauthError($request, 'denied');
        }

        $code = $request->query('code');
        if (! $this->configured() || ! is_string($expectedNonce) || ! is_string($code) || $code === '') {
            return $this->oauthError($request, 'invalid_identity');
        }

        try {
            $tokenResponse = Http::asForm()
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(12)
                ->post('https://oauth2.googleapis.com/token', [
                    'code' => $code,
                    'client_id' => config('services.google.client_id'),
                    'client_secret' => config('services.google.client_secret'),
                    'redirect_uri' => config('services.google.redirect'),
                    'grant_type' => 'authorization_code',
                    'code_verifier' => $codeVerifier,
                ])
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException $exception) {
            report($exception);

            return $this->oauthError($request, 'provider_error');
        }

        $idToken = is_array($tokenResponse) ? ($tokenResponse['id_token'] ?? null) : null;
        if (! is_string($idToken) || $idToken === '') {
            return $this->oauthError($request, 'invalid_identity');
        }

        try {
            $googleKeys = $this->googleSigningKeys();
        } catch (ConnectionException|RequestException $exception) {
            report($exception);

            return $this->oauthError($request, 'provider_error');
        } catch (\RuntimeException $exception) {
            report($exception);

            return $this->oauthError($request, 'provider_error');
        }

        $googleKeys['keys'] = array_values(array_filter(
            $googleKeys['keys'],
            static fn (mixed $key): bool => is_array($key)
                && ($key['kty'] ?? null) === 'RSA'
                && ($key['use'] ?? 'sig') === 'sig'
                && ($key['alg'] ?? 'RS256') === 'RS256'
        ));

        try {
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($googleKeys, 'RS256'));
        } catch (\Throwable $exception) {
            report($exception);

            return $this->oauthError($request, 'invalid_identity');
        }

        $identity = $this->verifiedIdentity($claims, $expectedNonce);
        if ($identity === null) {
            return $this->oauthError($request, 'invalid_identity');
        }

        $user = User::where('google_id', $identity['google_id'])->first();
        if (! $user) {
            $user = User::where('email', $identity['email'])->first();
        }

        if ($user) {
            if ($user->is_admin || $user->is_dummy) {
                return $this->oauthError($request, 'account_unavailable');
            }
            if ($user->google_id !== null && ! hash_equals((string) $user->google_id, $identity['google_id'])) {
                return $this->oauthError($request, 'linked_account');
            }

            $user->forceFill([
                'google_id' => $identity['google_id'],
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            $this->completeLogin($request, $user);

            return redirect('/#/dashboard');
        }

        $request->session()->put('google_onboarding', [
            'google_id' => $identity['google_id'],
            'email' => $identity['email'],
            'name' => $identity['name'],
            'referral_code' => $request->session()->pull('google_signup_referral', ''),
        ]);

        return redirect('/#/google-onboarding');
    }

    public function onboarding(Request $request): JsonResponse
    {
        $identity = $request->session()->get('google_onboarding');
        if (! $this->isPendingIdentity($identity)) {
            return response()->json(['message' => 'Your Google sign-up session expired. Please start again.'], 410);
        }

        return response()->json([
            'email' => $identity['email'],
            'name' => $identity['name'],
            'referral_code' => $identity['referral_code'],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $identity = $request->session()->get('google_onboarding');
        abort_unless($this->isPendingIdentity($identity), 409, 'Your Google sign-up session expired. Please start again.');

        $request->merge([
            'name' => trim((string) $request->input('name')),
            'phone' => trim((string) $request->input('phone')),
            'address' => trim((string) $request->input('address')),
            'referral_code' => Str::upper(trim((string) $request->input('referral_code'))),
        ]);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:80'],
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
        ]);

        try {
            $user = DB::transaction(function () use ($identity, $input): User {
                $referrer = User::where('referral_code', $input['referral_code'])->firstOrFail();
                $user = new User([
                    'name' => $input['name'],
                    'email' => $identity['email'],
                    'phone' => $input['phone'],
                    'date_of_birth' => $input['date_of_birth'],
                    'address' => $input['address'],
                    'gender' => $input['gender'],
                    'marital_status' => $input['marital_status'],
                    'password' => Str::random(64),
                ]);
                $user->referrer()->associate($referrer);
                $user->forceFill([
                    'google_id' => $identity['google_id'],
                    'email_verified_at' => now(),
                ]);
                $user->save();
                Wallet::create(['user_id' => $user->id]);

                return $user;
            });
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages([
                    'email' => 'An account already exists for this Google email. Please sign in instead.',
                ]);
            }

            throw $exception;
        }

        $request->session()->forget(['google_onboarding', 'google_signup_referral']);

        $this->completeLogin($request, $user);
        app(WebPushNotificationService::class)->notifyAdmins(
            'A new member has created an account. Open the member directory to view the account.',
            '/#/admin?tab=members',
            'New user registered'
        );

        return response()->json(app(PortalController::class)->payloadForUser($user));
    }

    private function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    private function googleSigningKeys(): array
    {
        $keys = Cache::remember('google.oauth.signing_keys', now()->addHour(), static function (): array {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(12)
                ->get('https://www.googleapis.com/oauth2/v3/certs')
                ->throw()
                ->json();

            if (! is_array($response) || ! isset($response['keys']) || ! is_array($response['keys'])) {
                throw new \RuntimeException('Google returned an invalid signing-key set.');
            }

            return $response;
        });

        if (! isset($keys['keys']) || ! is_array($keys['keys'])) {
            throw new \RuntimeException('The cached Google signing-key set is invalid.');
        }

        return $keys;
    }

    private function verifiedIdentity(array $claims, string $expectedNonce): ?array
    {
        $clientId = (string) config('services.google.client_id');
        $audience = $claims['aud'] ?? null;
        $audienceValid = is_string($audience)
            ? hash_equals($clientId, $audience)
            : is_array($audience) && in_array($clientId, $audience, true);
        $authorizedParty = $claims['azp'] ?? (is_array($audience) && count($audience) > 1 ? null : $clientId);
        $issuerValid = in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true);
        $nonce = $claims['nonce'] ?? null;
        $email = Str::lower(trim((string) ($claims['email'] ?? '')));
        $issuedAt = $claims['iat'] ?? null;
        $expiresAt = $claims['exp'] ?? null;
        $emailVerified = ($claims['email_verified'] ?? false) === true;

        if (! is_string($nonce) || ! hash_equals($expectedNonce, $nonce)
            || ! $issuerValid || ! $audienceValid || ! is_string($authorizedParty)
            || ! hash_equals($clientId, $authorizedParty)
            || ! $emailVerified || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || ! is_numeric($issuedAt) || (int) $issuedAt > now()->timestamp + 60
            || ! is_numeric($expiresAt) || (int) $expiresAt <= now()->timestamp) {
            return null;
        }

        $googleId = $claims['sub'] ?? null;
        if (! is_string($googleId) || $googleId === '' || strlen($googleId) > 255) {
            return null;
        }

        return [
            'google_id' => $googleId,
            'email' => $email,
            'name' => mb_substr(trim((string) ($claims['name'] ?? '')), 0, 80),
        ];
    }

    private function isPendingIdentity(mixed $identity): bool
    {
        return is_array($identity)
            && isset($identity['google_id'], $identity['email'])
            && is_string($identity['google_id'])
            && is_string($identity['email'])
            && $identity['google_id'] !== ''
            && filter_var($identity['email'], FILTER_VALIDATE_EMAIL) !== false;
    }

    private function completeLogin(Request $request, User $user): void
    {
        Auth::login($user, true);
        $request->session()->forget(['google_oauth_state', 'google_oauth_nonce', 'google_oauth_verifier', 'google_onboarding', 'google_signup_referral']);
        $request->session()->regenerate();
        app(MemberSignInRecorder::class)->record($request, $user);
        app(InvestmentReminderService::class)->sendIfEligible($user);

    }

    private function oauthError(Request $request, string $error): RedirectResponse
    {
        $request->session()->forget(['google_oauth_state', 'google_oauth_nonce', 'google_oauth_verifier', 'google_onboarding', 'google_signup_referral']);

        return redirect('/#/login?google_error='.rawurlencode($error));
    }
}
