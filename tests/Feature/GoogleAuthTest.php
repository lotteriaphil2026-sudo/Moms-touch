<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private const GOOGLE_TEST_PRIVATE_KEY = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQCqFgAP3GfP/7tr
1IZX7obLB+gkB96oPClzPGgxxG5qI21t+dYSvyDLW778ODN4oCp0RrQv8sH58iOf
BMLbC7gpA+Leo5dMJEX7aB7qD+f5mOf71JOFRXPLTRxJUTJpCimFBRwY1uqvpq3O
NgJflX8idftlH2RpMAkj/iNtsm/F5DQXWiyK8Fe6gogZMHSvt+1gIINCH1f04QE2
WHsaziIsOTiMAwf0B6+84G4eI05B86ysu6eagvQUeSOxYtayU9g5hoESiyULV/gt
V/kr6yxzrIEHCL5gtBSntPyqadLYeijcyIpsOuekikw/FlJno10xh6pbaEN7hMYc
YXe2qTU7AgMBAAECggEACfdACa3gZ0+Uq7zIIl7Xhw3vjCzp3vGnfBkOAdyAm7d3
sfnxkSehxEib6Cti2K6JzkZWSHjw5LRLYgXlZPBK2hxgLqtnMg7uCs0PAqfr77QF
47uQ6T4oisD551Haokz2IdUq0EnHdoNNq8d+XBtmV56SHFq6h29JGdLKQKMz6WRd
MFhBWFD1GS6LpdIb0ZXmb1WoXhwx7I0dq6RbpR3EjJVitGJ7k7qUodxE5cqFLVwn
0Fnfu5DK524EdfVLRwoinYWCZIUhI7wNZZkY5O1U6AhjTmubvx8WU1pN2RluF6KB
nUatN726PjHSC4tetndZlCpjUMofsa5T8Q7KD714AQKBgQDY3FaYNMD9LFAKxXqV
iBKAquE6o/NyacEqS+agF1s9n/O2b14IealHlvK+B1XilCYvPwb4Lpj+aU49I7TQ
RIkkRpMda/0nqoyJpDjGfL7meX/n4DMVZjkSeyq+4V+fhJAL6EyIU52Pslwq8d3d
3jPG7imq8l+1ozuMy+G/PyN6hwKBgQDIyISIkLK54SeAPGSyG8xbStdNszNcbVJb
qk88g+NQqGjBdeYRMn3Zc7wrr1/jr9jg3IbQICVC/LTGSdRXvW5TcEaYULhHdC6O
bPShv9MVnp8K1MYMh5/6i25tjvRjQb3DXpks3kjx0WA/WA888qG3oPgqZgEOH+Jy
9JVhGSdYrQKBgGWYfF0cQdmaG/mf4LkTIY/z/kaJrrDISL23B1my94TcXcH3VLdl
v9+RITHTn/HhdC4oOeylb/mofgX4p2n24cUD50IeaGBf7y1AqXSb1peFDl4kU+pP
lKdK2pDIZxMFeLKLZudrPOKoLbPU0htyURF+XDkBCcO/sFNa5OzC+K0bAoGBALHA
KuzHxSIf/Rte2/p9952+lPsZsVebBLVYAgvd6skGPq8+k+4XmCiBeE3oVWi5zbPM
J6FrQ/GYzOVaiR0f++LsW+MOWwYPCgRW2v+zVcknAz/QfICtNOjWtW1SiHKyya+D
OPHq3AMQzDdn2x2m45owbGMEqNx7R7tjYjdy0E2FAoGADbrzWA8IcnF2NiqoLNxb
HY9+Yu5dnei7aGdz2loJO44hDlIPkUjswuBqgbHDksRnB9LWzEy0pokFsgTiEgws
gP6epvgV8oT0dHdmlJ04utsNoubY/udasdTpx2n/mknp0a2Td4evohL/kZo5xDHJ
51LR1keeW0SEfbRCHY/YHJQ=
-----END PRIVATE KEY-----
PEM;

    private const GOOGLE_TEST_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAqhYAD9xnz/+7a9SGV+6G
ywfoJAfeqDwpczxoMcRuaiNtbfnWEr8gy1u+/DgzeKAqdEa0L/LB+fIjnwTC2wu4
KQPi3qOXTCRF+2ge6g/n+Zjn+9SThUVzy00cSVEyaQophQUcGNbqr6atzjYCX5V/
InX7ZR9kaTAJI/4jbbJvxeQ0F1osivBXuoKIGTB0r7ftYCCDQh9X9OEBNlh7Gs4i
LDk4jAMH9AevvOBuHiNOQfOsrLunmoL0FHkjsWLWslPYOYaBEoslC1f4LVf5K+ss
c6yBBwi+YLQUp7T8qmnS2Hoo3MiKbDrnpIpMPxZSZ6NdMYeqW2hDe4TGHGF3tqk1
OwIDAQAB
-----END PUBLIC KEY-----
PEM;

    public function test_google_redirect_uses_configured_client_and_a_session_bound_nonce(): void
    {
        $this->configureGoogle();

        $response = $this->get('/auth/google/redirect?ref=MEMBER-REF')
            ->assertRedirect();
        $query = [];
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth', strtok($response->headers->get('Location'), '?'));
        $this->assertSame('google-client-id', $query['client_id']);
        $this->assertSame('https://portal.example.com/auth/google/callback', $query['redirect_uri']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame('select_account', $query['prompt']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(
            $this->base64UrlEncode(hash('sha256', (string) session('google_oauth_verifier'), true)),
            $query['code_challenge']
        );
        $this->assertSame($query['state'], session('google_oauth_state'));
        $this->assertSame($query['nonce'], session('google_oauth_nonce'));
        $this->assertSame('MEMBER-REF', session('google_signup_referral'));
    }

    public function test_google_callback_verifies_identity_and_signs_in_matching_member(): void
    {
        $member = User::factory()->create(['email' => 'member@example.test']);
        Wallet::create(['user_id' => $member->id]);
        $this->configureGoogle();
        $token = $this->signedGoogleToken([
            'sub' => 'google-member-123',
            'email' => 'member@example.test',
            'email_verified' => true,
            'name' => 'Portal Member',
            'nonce' => 'oauth-nonce',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['id_token' => $token]),
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response($this->googleJwks()),
        ]);

        $this->withSession([
            'google_oauth_state' => 'oauth-state',
            'google_oauth_nonce' => 'oauth-nonce',
            'google_oauth_verifier' => 'oauth-code-verifier',
        ])->get('/auth/google/callback?state=oauth-state&code=authorization-code')
            ->assertRedirect('/#/dashboard');

        $this->assertAuthenticatedAs($member);
        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'google_id' => 'google-member-123',
        ]);
        $this->assertDatabaseHas('member_sign_ins', ['user_id' => $member->id]);
        Http::assertSent(static fn ($request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['code_verifier'] === 'oauth-code-verifier');
    }

    public function test_google_signup_requires_onboarding_details_and_creates_a_referred_member(): void
    {
        $referrer = User::factory()->create();
        Wallet::create(['user_id' => $referrer->id]);
        $this->withSession(['google_onboarding' => [
            'google_id' => 'google-new-member',
            'email' => 'new-member@example.test',
            'name' => 'New Member',
            'referral_code' => $referrer->referral_code,
        ]])->getJson('/portal/google-onboarding')
            ->assertOk()
            ->assertJsonPath('email', 'new-member@example.test')
            ->assertJsonPath('name', 'New Member');

        $this->postJson('/portal/google-register', [
            'name' => 'New Member',
            'referral_code' => $referrer->referral_code,
            'phone' => '+639171234567',
            'date_of_birth' => '1990-01-01',
            'address' => '123 Example Street, Makati City',
            'gender' => 'Other',
            'marital_status' => 'Single',
        ])->assertOk()->assertJsonPath('user.email', 'new-member@example.test');

        $member = User::where('email', 'new-member@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($member);
        $this->assertSame($referrer->id, $member->referrer_id);
        $this->assertSame('google-new-member', $member->google_id);
        $this->assertNotNull($member->email_verified_at);
        $this->assertDatabaseHas('wallets', ['user_id' => $member->id]);
        $this->assertDatabaseHas('member_sign_ins', ['user_id' => $member->id]);
    }

    public function test_google_callback_rejects_unverified_email_and_invalid_state(): void
    {
        $this->configureGoogle();
        $token = $this->signedGoogleToken([
            'sub' => 'unverified-google-user',
            'email' => 'unverified@example.test',
            'email_verified' => false,
            'nonce' => 'oauth-nonce',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['id_token' => $token]),
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response($this->googleJwks()),
        ]);

        $this->withSession([
            'google_oauth_state' => 'oauth-state',
            'google_oauth_nonce' => 'oauth-nonce',
            'google_oauth_verifier' => 'oauth-code-verifier',
        ])->get('/auth/google/callback?state=oauth-state&code=authorization-code')
            ->assertRedirect('/#/login?google_error=invalid_identity');
        $this->assertGuest();

        $this->withSession(['google_oauth_state' => 'expected-state'])
            ->get('/auth/google/callback?state=wrong-state&code=authorization-code')
            ->assertRedirect('/#/login?google_error=invalid_state');
        $this->assertDatabaseCount('users', 0);
    }

    private function configureGoogle(): void
    {
        config([
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.redirect' => 'https://portal.example.com/auth/google/callback',
        ]);
        Cache::forget('google.oauth.signing_keys');
    }

    private function signedGoogleToken(array $claims): string
    {
        $privateKey = openssl_pkey_get_private(self::GOOGLE_TEST_PRIVATE_KEY);
        $this->assertNotFalse($privateKey);

        return JWT::encode($claims + [
            'iss' => 'https://accounts.google.com',
            'aud' => 'google-client-id',
            'azp' => 'google-client-id',
            'iat' => now()->timestamp,
            'exp' => now()->addMinutes(5)->timestamp,
        ], $privateKey, 'RS256', 'google-test-key');
    }

    private function googleJwks(): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_public(self::GOOGLE_TEST_PUBLIC_KEY));
        $this->assertIsArray($details);

        return ['keys' => [[
            'kty' => 'RSA',
            'kid' => 'google-test-key',
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => $this->base64UrlEncode($details['rsa']['n']),
            'e' => $this->base64UrlEncode($details['rsa']['e']),
        ]]];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
