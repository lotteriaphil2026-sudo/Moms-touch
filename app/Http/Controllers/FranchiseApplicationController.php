<?php
namespace App\Http\Controllers;
use App\Models\FranchiseApplication;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule, ValidationException};
class FranchiseApplicationController extends Controller
{
    public function store(Request $request, PortalController $portal): JsonResponse
    {
        $input = $request->validate([
            'request_id' => ['required', 'uuid'],
            'contact_name' => ['required', 'string', 'min:2', 'max:80'],
            'contact_email' => ['required', 'email', 'max:254'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[+\d][\d\s().-]+$/', function ($attribute, $value, $fail) {
                $length = strlen(preg_replace('/\D/', '', $value));
                if ($length < 7 || $length > 20) { $fail('Please enter a valid contact number.'); }
            }],
            'country' => ['required', 'string', 'max:80'],
            'city' => ['required', 'string', 'max:100'],
            'region' => ['required', 'string', 'max:100'],
            'site_status' => ['required', Rule::in(['exploring', 'proposed', 'secured'])],
            'budget' => ['nullable', 'string', 'max:12', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'target_opening' => ['nullable', 'date_format:Y-m'],
            'experience' => ['nullable', 'string', 'max:2000'],
            'consent' => ['required', 'accepted'],
        ]);
        $requestId = $input['request_id'];
        $data = collect($input)->except(['request_id', 'consent', 'budget'])->all();
        $data['contact_email'] = strtolower($data['contact_email']);
        $data['budget_cents'] = null;
        if (isset($input['budget'])) {
            [$pesos, $centavos] = array_pad(explode('.', $input['budget'], 2), 2, '');
            $cents = (int) $pesos * 100 + (int) str_pad($centavos, 2, '0');
            if ($cents < 1 || $cents > 10000000000) {
                throw ValidationException::withMessages(['budget' => 'Enter a budget between ₱0.01 and ₱100,000,000.']);
            }
            $data['budget_cents'] = $cents;
        }
        ksort($data);
        $fingerprint = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE));
        DB::transaction(function () use ($request, $data, $requestId, $fingerprint) {
            // Serialize per member so retried submissions cannot create duplicate applications.
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $existing = FranchiseApplication::where('user_id', $request->user()->id)->where('request_id', $requestId)->first();
            if ($existing) {
                if (!hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['request_id' => 'This application reference has already been used with different details.']);
                }
                return;
            }
            FranchiseApplication::create([
                ...$data,
                'user_id' => $request->user()->id,
                'reference' => 'MTF-'.Str::upper(Str::random(10)),
                'request_id' => $requestId, 'request_fingerprint' => $fingerprint,
                'status' => 'submitted', 'consent_at' => now(),
            ]);
        }, 3);
        return $portal->state($request);
    }
}
