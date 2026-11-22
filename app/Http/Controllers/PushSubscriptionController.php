<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
            'keys' => ['required', 'array:p256dh,auth'],
            'keys.p256dh' => ['required', 'string', 'min:80', 'max:200', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'keys.auth' => ['required', 'string', 'min:16', 'max:64', 'regex:/\A[A-Za-z0-9_-]+\z/'],
        ]);

        $this->assertAllowedEndpoint($input['endpoint']);
        if (! $this->hasDecodedLength($input['keys']['p256dh'], 65)
            || ! $this->hasDecodedLength($input['keys']['auth'], 16)) {
            throw ValidationException::withMessages([
                'keys' => 'The browser supplied an invalid push subscription.',
            ]);
        }

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $input['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $input['endpoint'],
                'public_key' => $input['keys']['p256dh'],
                'auth_token' => $input['keys']['auth'],
                'content_encoding' => 'aes128gcm',
            ]
        );

        return response()->json(['subscribed' => true], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $input = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);
        $this->assertAllowedEndpoint($input['endpoint']);

        $deleted = PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $input['endpoint']))
            ->delete();

        return response()->json(['subscribed' => false, 'removed' => $deleted > 0]);
    }

    private function assertAllowedEndpoint(string $endpoint): void
    {
        if (! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'endpoint' => 'This push service endpoint is not supported.',
            ]);
        }

        try {
            $parts = parse_url($endpoint);
        } catch (\ValueError) {
            $parts = false;
        }

        if (! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || ! in_array(strtolower($parts['host'] ?? ''), config('webpush.endpoint_hosts'), true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw ValidationException::withMessages([
                'endpoint' => 'This push service endpoint is not supported.',
            ]);
        }
    }

    private function hasDecodedLength(string $value, int $bytes): bool
    {
        $base64 = strtr($value, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $decoded = base64_decode($base64, true);

        return $decoded !== false && strlen($decoded) === $bytes;
    }
}
