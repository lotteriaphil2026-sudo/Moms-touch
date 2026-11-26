<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

class MemberSignInRecorder
{
    public function record(Request $request, User $user): void
    {
        $ipAddress = $request->ip();
        $ipAddress = filter_var($ipAddress, FILTER_VALIDATE_IP) ? $ipAddress : null;
        $country = $this->locationHeader($request, ['CF-IPCountry', 'CloudFront-Viewer-Country', 'X-Appengine-Country'], 2);

        if ($country !== null && ! preg_match('/\A[A-Za-z0-9]{2}\z/', $country)) {
            $country = null;
        }

        $user->signIns()->create([
            'ip_address' => $ipAddress,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000) ?: null,
            'country' => $country === null ? null : strtoupper($country),
            'region' => $this->locationHeader($request, ['CF-Region', 'CloudFront-Viewer-Country-Region', 'X-Appengine-Region'], 100),
            'city' => $this->locationHeader($request, ['CF-IPCity', 'CloudFront-Viewer-City', 'X-Appengine-City'], 100),
        ]);
    }

    private function locationHeader(Request $request, array $headers, int $maxLength): ?string
    {
        foreach ($headers as $header) {
            $value = preg_replace('/[\x00-\x1F\x7F]/u', '', trim((string) $request->header($header, ''))) ?? '';
            if ($value !== '') {
                return mb_substr($value, 0, $maxLength);
            }
        }

        return null;
    }
}
