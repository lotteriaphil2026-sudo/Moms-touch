<?php

namespace Tests\Feature;

use App\Models\{User, Wallet, WalletRequest};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DepositBankFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.financial_operations_enabled' => true, 'portal.deposit_qr_demo_mode' => true]);
        Storage::fake('local');
    }

    private function account(bool $admin = false, bool $dummy = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => $admin, 'is_dummy' => $dummy])->save();
        Wallet::create(['user_id' => $user->id]);

        return $user;
    }

    private function submitDeposit(array $payload)
    {
        return $this->post('/portal/transactions', [
            ...$payload,
            'deposit_plan' => $payload['deposit_plan'] ?? 'select',
            'receipt' => $this->receiptUpload(),
        ], ['Accept' => 'application/json']);
    }

    private function receiptUpload(?int $sizeKilobytes = null): UploadedFile
    {
        $upload = UploadedFile::fake()->createWithContent(
            'receipt.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lGkAAAAASUVORK5CYII=')
        );

        if ($sizeKilobytes !== null) {
            $upload->size($sizeKilobytes);
        }

        return $upload;
    }

    public function test_demo_bank_deposit_is_saved_as_test_only_and_cannot_be_approved(): void
    {
        $member = $this->account();
        $admin = $this->account(true);

        $response = $this->actingAs($member)->submitDeposit([
            'type' => 'deposit',
            'amount' => '5000',
            'payment_method' => 'bpi',
            'request_id' => (string) Str::uuid(),
        ]);

        $response->assertOk()
            ->assertJsonPath('submitted_request.payment_method', 'bpi')
            ->assertJsonPath('submitted_request.test_only', true)
            ->assertJsonPath('submitted_request.status', 'pending');

        $request = WalletRequest::firstOrFail();
        $this->actingAs($admin)->postJson('/portal/admin/requests/'.$request->id.'/review', [
            'decision' => 'approve',
            'note' => 'Test transfer',
        ])->assertUnprocessable();

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(0, Wallet::where('user_id', $member->id)->firstOrFail()->balance_cents);
    }

    public function test_deposit_requests_reject_unsupported_banks(): void
    {
        $member = $this->account();
        $request = [
            'type' => 'deposit',
            'amount' => '500',
            'request_id' => (string) Str::uuid(),
        ];

        $this->actingAs($member)->submitDeposit($request)
            ->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->submitDeposit([...$request, 'payment_method' => 'other-bank'])
            ->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        $this->assertDatabaseCount('wallet_requests', 0);
    }

    public function test_deposit_requests_require_at_least_five_thousand_pesos(): void
    {
        $member = $this->account();
        $payload = [
            'type' => 'deposit',
            'amount' => '4999.99',
            'payment_method' => 'bpi',
            'request_id' => (string) Str::uuid(),
        ];

        $this->actingAs($member)->submitDeposit($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('wallet_requests', 0);

        $this->submitDeposit([...$payload, 'amount' => '5000', 'request_id' => (string) Str::uuid()])
            ->assertOk()
            ->assertJsonPath('submitted_request.amount', 500000);
    }

    public function test_deposit_plan_ranges_are_enforced_and_signature_has_no_upper_cap(): void
    {
        $member = $this->account();
        $submit = fn (string $plan, string $amount) => $this->actingAs($member)->submitDeposit([
            'type' => 'deposit',
            'amount' => $amount,
            'deposit_plan' => $plan,
            'payment_method' => 'bpi',
            'request_id' => (string) Str::uuid(),
        ]);

        $submit('select', '5000.00')->assertOk();
        $submit('select', '49999.99')->assertOk();
        $submit('select', '50000')->assertUnprocessable()->assertJsonValidationErrors('amount');
        $submit('select', '51000')->assertUnprocessable()->assertJsonValidationErrors('amount');
        $submit('prime', '49999.99')->assertUnprocessable()->assertJsonValidationErrors('amount');
        $submit('prime', '50000')->assertOk();
        $submit('prime', '249999.99')->assertOk();
        $submit('prime', '250000')->assertUnprocessable()->assertJsonValidationErrors('amount');
        $submit('signature', '250000')->assertOk();
        $submit('signature', '1000001.00')->assertOk();
    }

    public function test_configured_bank_deposit_uses_real_qr_mode(): void
    {
        config(['portal.deposit_qr_demo_mode' => false]);
        $member = $this->account();

        $this->actingAs($member)->submitDeposit([
            'type' => 'deposit',
            'amount' => '5000',
            'payment_method' => 'bpi',
            'request_id' => (string) Str::uuid(),
        ])
            ->assertOk()
            ->assertJsonPath('submitted_request.payment_method', 'bpi')
            ->assertJsonPath('submitted_request.test_only', false);

        $this->assertDatabaseHas('wallet_requests', [
            'payment_method' => 'bpi',
            'test_only' => false,
        ]);
    }

    public function test_palawan_pay_is_an_available_payment_method_with_its_assets(): void
    {
        config(['portal.deposit_qr_demo_mode' => false]);
        $member = $this->account();

        $this->actingAs($member)->submitDeposit([
            'type' => 'deposit',
            'amount' => '5000',
            'payment_method' => 'palawan_pay',
            'request_id' => (string) Str::uuid(),
        ])
            ->assertOk()
            ->assertJsonPath('submitted_request.payment_method', 'palawan_pay')
            ->assertJsonPath('submitted_request.test_only', false);

        $this->assertDatabaseHas('wallet_requests', [
            'user_id' => $member->id,
            'payment_method' => 'palawan_pay',
            'test_only' => false,
        ]);
        $this->assertFileExists(public_path('assets/Palawan Pay.svg'));
        $this->assertFileExists(public_path('assets/Palawan-Pay-Qr.svg'));
    }

    public function test_demo_cleanup_migration_deletes_test_requests_and_preserves_audit_history(): void
    {
        $member = $this->account();
        $admin = $this->account(true);

        $this->actingAs($member)->submitDeposit([
            'type' => 'deposit',
            'amount' => '5000',
            'payment_method' => 'bpi',
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $demoRequest = WalletRequest::firstOrFail();

        $this->actingAs($admin)->postJson('/portal/admin/requests/'.$demoRequest->id.'/review', [
            'decision' => 'reject',
            'note' => 'Demo record cleanup test',
        ])->assertOk();

        config(['portal.deposit_qr_demo_mode' => false]);
        $this->actingAs($member)->submitDeposit([
            'type' => 'deposit',
            'amount' => '5000',
            'payment_method' => 'bpi',
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $realRequest = WalletRequest::where('test_only', false)->firstOrFail();

        $migration = require database_path('migrations/2026_11_24_000002_delete_test_only_deposit_requests.php');
        $migration->up();

        $this->assertDatabaseMissing('wallet_requests', ['id' => $demoRequest->id]);
        $this->assertDatabaseHas('wallet_requests', ['id' => $realRequest->id, 'test_only' => false]);
        $this->assertDatabaseHas('admin_actions', [
            'action' => 'request_rejected',
            'wallet_request_id' => null,
            'note' => 'Demo record cleanup test',
        ]);
    }

    public function test_deposit_requires_a_valid_receipt_and_admin_receipt_logs_are_private(): void
    {
        $member = $this->account();
        $admin = $this->account(true);
        $payload = [
            'type' => 'deposit',
            'amount' => '5000',
            'deposit_plan' => 'select',
            'payment_method' => 'bpi',
            'request_id' => (string) Str::uuid(),
        ];

        $this->actingAs($member)->postJson('/portal/transactions', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receipt');
        $this->assertDatabaseCount('wallet_requests', 0);

        $this->submitDeposit($payload)
            ->assertOk()
            ->assertJsonPath('submitted_request.has_receipt', true);
        $deposit = WalletRequest::firstOrFail();
        Storage::disk('local')->assertExists($deposit->receipt_path);

        $this->actingAs($admin)->getJson('/portal/admin/receipt-logs')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.reference', $deposit->reference);
        $this->get('/portal/admin/requests/'.$deposit->id.'/receipt')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->actingAs($member)->get('/portal/admin/requests/'.$deposit->id.'/receipt')->assertForbidden();
    }

    public function test_admin_receipt_logs_ignore_missing_stored_files(): void
    {
        $member = $this->account();
        $admin = $this->account(true);

        $payload = [
            'type' => 'deposit',
            'amount' => '5000',
            'deposit_plan' => 'select',
            'payment_method' => 'bpi',
            'request_id' => (string) Str::uuid(),
        ];

        $this->actingAs($member)->submitDeposit($payload)->assertOk();
        $deposit = WalletRequest::firstOrFail();
        Storage::disk('local')->assertExists($deposit->receipt_path);
        Storage::disk('local')->delete($deposit->receipt_path);

        $this->actingAs($admin)->getJson('/portal/admin/receipt-logs')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonCount(0, 'items');
    }

    public function test_deposit_receipt_rejects_non_image_uploads(): void
    {
        $member = $this->account();
        $this->actingAs($member)->post('/portal/transactions', [
            'type' => 'deposit',
            'amount' => '5000',
            'deposit_plan' => 'select',
            'payment_method' => 'bpi',
            'request_id' => (string) Str::uuid(),
            'receipt' => UploadedFile::fake()->create('receipt.txt', 10, 'text/plain'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receipt');
        $this->assertDatabaseCount('wallet_requests', 0);
    }

    public function test_deposit_receipts_accept_ten_megabytes_and_reject_larger_files(): void
    {
        $member = $this->account();
        $payload = [
            'type' => 'deposit',
            'amount' => '5000',
            'deposit_plan' => 'select',
            'payment_method' => 'bpi',
            'request_id' => (string) Str::uuid(),
        ];

        $this->actingAs($member)->post('/portal/transactions', [
            ...$payload,
            'receipt' => $this->receiptUpload(10240),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('submitted_request.has_receipt', true);

        $this->post('/portal/transactions', [
            ...$payload,
            'request_id' => (string) Str::uuid(),
            'receipt' => $this->receiptUpload(10241),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receipt');

        $this->assertDatabaseCount('wallet_requests', 1);
    }

    public function test_dummy_member_can_submit_a_deposit_without_a_receipt(): void
    {
        $dummy = $this->account(dummy: true);

        $this->actingAs($dummy)->postJson('/portal/transactions', [
            'type' => 'deposit',
            'amount' => '5000',
            'deposit_plan' => 'select',
            'payment_method' => 'bpi',
            'request_id' => (string) Str::uuid(),
        ])
            ->assertOk()
            ->assertJsonPath('user.is_dummy', true)
            ->assertJsonPath('submitted_request.has_receipt', false);

        $this->assertDatabaseHas('wallet_requests', [
            'user_id' => $dummy->id,
            'type' => 'deposit',
            'receipt_path' => null,
        ]);
    }
}
