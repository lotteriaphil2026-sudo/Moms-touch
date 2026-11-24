<?php
namespace Tests\Feature;
use App\Jobs\SendWebPushNotification;
use App\Models\{InvestmentAllocation, PushSubscription, User, Wallet, WalletRequest, WithdrawalAccount};
use App\Services\{WalletService, WalletRequestService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Hash, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;
class AdminWorkflowTest extends TestCase
{
 use RefreshDatabase;
 protected function setUp(): void { parent::setUp();config(['portal.financial_operations_enabled'=>true,'portal.registration_enabled'=>true,'portal.deposit_qr_demo_mode'=>false]); }
 private function member(bool $admin=false,string $funds='0'): User {
  $u=User::factory()->create();$u->forceFill(['is_admin'=>$admin])->save();Wallet::create(['user_id'=>$u->id]);
  if($funds!=='0')app(WalletService::class)->transact($u,'deposit',$funds,null,(string)Str::uuid());return $u;
 }
 private function walletRequest(User $u,string $type,string $amount,?string $id=null): WalletRequest {$account=$type==='withdraw'?WithdrawalAccount::create(['user_id'=>$u->id,'type'=>'bank','provider'=>'BPI','account_holder'=>$u->name,'account_number'=>'1234567890']):null;return app(WalletRequestService::class)->submit($u,$type,$amount,$id??(string)Str::uuid(),'Member request',null,$account);}
 private function review(WalletRequest $r,string $decision='approve'){return $this->postJson('/portal/admin/requests/'.$r->id.'/review',['decision'=>$decision,'note'=>'Reviewed and checked']);}
 private function send(User $u,string $kind,array $data=[]){return $this->postJson('/portal/admin/users/'.$u->id.'/'.$kind,[...['amount'=>'5000.25','note'=>'Member credit','request_id'=>(string)Str::uuid()],...$data]);}
 public function test_guests_and_members_cannot_use_any_admin_endpoint(): void {
  $u=$this->member();$r=$this->walletRequest($u,'deposit','5000');
  $urls=['/portal/admin/overview','/portal/admin/users','/portal/admin/users/'.$u->id,'/portal/admin/deposit-users','/portal/admin/requests','/portal/admin/audit'];
  foreach($urls as $url)$this->getJson($url)->assertUnauthorized();$this->review($r)->assertUnauthorized();$this->send($u,'funds')->assertUnauthorized();
  $this->actingAs($u);foreach($urls as $url)$this->getJson($url)->assertForbidden();$this->getJson('/portal/admin/users/999999')->assertForbidden();$this->review($r)->assertForbidden();
  $this->send($u,'funds',['is_admin'=>true])->assertForbidden();$this->send($u,'plans',['plan'=>'select','is_admin'=>true])->assertForbidden();$this->assertDatabaseCount('admin_actions',0);
 }
 public function test_registration_and_profile_updates_cannot_elevate_roles(): void {
  $this->postJson('/portal/register',['name'=>'Member','email'=>'member@example.test','phone'=>'+639171234567','date_of_birth'=>'1990-01-01','address'=>'123 Example Street, Makati City','gender'=>'Other','marital_status'=>'Single','password'=>'Example-password12!','password_confirmation'=>'Example-password12!','is_admin'=>true,'role'=>'admin'])->assertOk()->assertJsonPath('user.is_admin',false);
  $this->patchJson('/portal/profile',['name'=>'Still a member','is_admin'=>true])->assertOk()->assertJsonPath('user.is_admin',false);$this->getJson('/portal/admin/users')->assertForbidden();
 }
 public function test_admin_sign_in_returns_the_server_assigned_role(): void {
  $admin=$this->member(true);
  $this->postJson('/portal/login',['email'=>$admin->email,'password'=>'password','is_admin'=>false])->assertOk()->assertJsonPath('user.is_admin',true)->assertJsonMissingPath('user.password');
  $this->getJson('/portal/admin/overview')->assertOk();
 }
 public function test_members_see_only_their_own_wallet_requests_and_cannot_choose_another_owner(): void {
  $first=$this->member();$second=$this->member();$this->walletRequest($first,'deposit','5000');$this->actingAs($second);
  $this->getJson('/portal/state')->assertOk()->assertJsonCount(0,'wallet_requests');
  $this->postJson('/portal/transactions',['type'=>'deposit','amount'=>'5000','payment_method'=>'bpi','request_id'=>(string)Str::uuid(),'user_id'=>$first->id])->assertOk()->assertJsonCount(1,'wallet_requests');
  $this->assertSame(1,WalletRequest::where('user_id',$first->id)->count());$this->assertSame(1,WalletRequest::where('user_id',$second->id)->count());
 }
 public function test_deposit_is_pending_until_approval_and_replays_do_not_duplicate_credit(): void {
  $u=$this->member();$admin=$this->member(true);$id=(string)Str::uuid();$this->actingAs($u);
  $payload=['type'=>'deposit','amount'=>'5000.25','payment_method'=>'bpi','request_id'=>$id];
  $this->postJson('/portal/transactions',[...$payload,'status'=>'approved','reviewed_by'=>$admin->id])->assertOk()->assertJsonPath('wallet.balance',0)->assertJsonPath('wallet_requests.0.status','pending');
  $this->postJson('/portal/transactions',$payload)->assertOk();$this->postJson('/portal/transactions',[...$payload,'amount'=>'1001'])->assertUnprocessable();
  $this->assertDatabaseCount('wallet_requests',1);$this->assertDatabaseCount('wallet_transactions',0);$r=WalletRequest::first();
  $this->actingAs($admin);$this->review($r)->assertOk();$this->review($r)->assertOk();$this->review($r,'reject')->assertUnprocessable();
  $this->assertSame(500025,Wallet::where('user_id',$u->id)->first()->balance_cents);$this->assertDatabaseCount('wallet_transactions',1);$this->assertDatabaseCount('admin_actions',1);
  $this->assertDatabaseHas('wallet_requests',['id'=>$r->id,'reviewed_by'=>$admin->id,'status'=>'approved']);
  $this->actingAs($u)->getJson('/portal/state')->assertOk()->assertJsonPath('wallet_requests.0.review_note','Reviewed and checked');
 }
 public function test_admin_deposit_queue_groups_members_and_member_endpoint_contains_all_deposit_requests(): void {
  $u=$this->member();$admin=$this->member(true);$first=$this->walletRequest($u,'deposit','5000');$second=$this->walletRequest($u,'deposit','7000');$other=$this->member();$this->walletRequest($other,'deposit','5000');
  $this->actingAs($admin);
  $pending=$this->getJson('/portal/admin/deposit-users')->assertOk()->json('items');
  $group=collect($pending)->firstWhere('user.id',$u->id);
  $this->assertNotNull($group);$this->assertSame(2,$group['request_count']);$this->assertSame(1200000,$group['total_amount']);
  $this->review($first)->assertOk();
  $pending=$this->getJson('/portal/admin/deposit-users')->assertOk()->json('items');
  $group=collect($pending)->firstWhere('user.id',$u->id);
  $this->assertNotNull($group);$this->assertSame(1,$group['request_count']);
  $this->getJson('/portal/admin/users/'.$u->id)->assertOk()->assertJsonCount(2,'wallet_requests');
  $all=$this->getJson('/portal/admin/deposit-users?status=all')->assertOk()->json('items');
  $group=collect($all)->firstWhere('user.id',$u->id);
  $this->assertNotNull($group);$this->assertSame(2,$group['request_count']);
 }
 public function test_withdrawal_reservation_prevents_spending_the_same_balance_twice(): void {
  $u=$this->member(false,'10000');$account=WithdrawalAccount::create(['user_id'=>$u->id,'type'=>'bank','provider'=>'BPI','account_holder'=>$u->name,'account_number'=>'1234567890']);$this->actingAs($u);
  $this->postJson('/portal/transactions',['type'=>'withdraw','amount'=>'6000','withdrawal_account_id'=>$account->id,'request_id'=>(string)Str::uuid()])->assertOk()->assertJsonPath('wallet.balance',400000)->assertJsonPath('wallet.held',600000);
  $this->postJson('/portal/transactions',['type'=>'withdraw','amount'=>'4000.01','withdrawal_account_id'=>$account->id,'request_id'=>(string)Str::uuid()])->assertUnprocessable();
  $this->postJson('/portal/transactions',['type'=>'invest','amount'=>'5000','plan'=>'select','request_id'=>(string)Str::uuid()])->assertUnprocessable();$this->assertDatabaseCount('wallet_requests',1);$this->assertDatabaseCount('investment_allocations',0);
 }
 public function test_withdrawal_approval_releases_hold_without_another_debit(): void {
  $u=$this->member(false,'10000');$admin=$this->member(true);$r=$this->walletRequest($u,'withdraw','1000.50');$this->actingAs($admin);$this->review($r)->assertOk();$this->review($r)->assertOk();
  $w=Wallet::where('user_id',$u->id)->first();$this->assertSame(899950,$w->balance_cents);$this->assertSame(0,$w->held_cents);$this->assertDatabaseCount('wallet_transactions',2);$this->assertDatabaseCount('admin_actions',1);
 }
 public function test_rejection_refunds_a_hold_once_and_never_credits_a_rejected_deposit(): void {
  $u=$this->member(false,'10000');$admin=$this->member(true);$w=$this->walletRequest($u,'withdraw','1000');$d=$this->walletRequest($u,'deposit','5000');$this->actingAs($admin);
  $this->review($w,'reject')->assertOk();$this->review($w,'reject')->assertOk();$this->review($w)->assertUnprocessable();$this->review($d,'reject')->assertOk();
  $wallet=Wallet::where('user_id',$u->id)->first();$this->assertSame(1000000,$wallet->balance_cents);$this->assertSame(0,$wallet->held_cents);$this->assertDatabaseCount('wallet_transactions',1);$this->assertDatabaseCount('admin_actions',2);
 }
 public function test_admin_credits_are_exact_idempotent_and_bound_to_recipient_and_amount(): void {
  Queue::fake();config(['webpush.vapid.subject'=>'mailto:push@example.test','webpush.vapid.public_key'=>'public','webpush.vapid.private_key'=>'private']);
  $u=$this->member();$other=$this->member();$admin=$this->member(true);$endpoint='https://fcm.googleapis.com/fcm/send/admin-funds';
  PushSubscription::create(['user_id'=>$u->id,'endpoint_hash'=>hash('sha256',$endpoint),'endpoint'=>$endpoint,'public_key'=>str_repeat('A',87),'auth_token'=>str_repeat('B',22),'content_encoding'=>'aes128gcm']);
  $this->actingAs($admin);$id=(string)Str::uuid();
  $this->send($u,'funds',['request_id'=>$id])->assertOk()->assertJsonPath('notification_queued',true)->assertJsonPath('member.wallet.balance',500025)->assertJsonPath('member.transactions.0.type','deposit')->assertJsonPath('member.transactions.0.description','Funds sent by admin');
  Queue::assertPushed(SendWebPushNotification::class,fn (SendWebPushNotification $job)=>$job->userId===$u->id&&$job->url==='/#/wallet'&&str_contains($job->message,'Funds were credited'));
  $this->send($u,'funds',['request_id'=>$id])->assertOk()->assertJsonPath('notification_queued',null)->assertJsonPath('member.wallet.balance',500025);
  Queue::assertPushed(SendWebPushNotification::class,1);
  $this->send($u,'funds',['request_id'=>$id,'amount'=>'6000'])->assertUnprocessable();$this->send($other,'funds',['request_id'=>$id])->assertUnprocessable();
  $this->actingAs($u)->getJson('/portal/state')->assertOk()->assertJsonPath('transactions.0.type','deposit')->assertJsonPath('transactions.0.description','Funds sent by admin');
  $this->assertDatabaseCount('wallet_transactions',1);$this->assertDatabaseCount('admin_actions',1);$this->assertDatabaseHas('admin_actions',['actor_id'=>$admin->id,'user_id'=>$u->id,'action'=>'funds_sent','amount_cents'=>500025]);$this->assertSame(0,Wallet::where('user_id',$other->id)->first()->balance_cents);
 }
 public function test_admin_fund_response_reports_when_recipient_has_no_phone_subscription(): void {
  Queue::fake();config(['webpush.vapid.subject'=>'mailto:push@example.test','webpush.vapid.public_key'=>'public','webpush.vapid.private_key'=>'private']);
  $u=$this->member();$admin=$this->member(true);$this->actingAs($admin);
  $this->send($u,'funds')->assertOk()->assertJsonPath('notification_queued',false)->assertJsonPath('member.wallet.balance',500025);
  Queue::assertNotPushed(SendWebPushNotification::class);
 }
 public function test_admin_plan_grant_preserves_cash_snapshots_terms_and_accrues_correctly(): void {
  Queue::fake();config(['webpush.vapid.subject'=>'mailto:push@example.test','webpush.vapid.public_key'=>'public','webpush.vapid.private_key'=>'private']);
  $u=$this->member(false,'1000');$admin=$this->member(true);$endpoint='https://fcm.googleapis.com/fcm/send/assigned-plan';
  PushSubscription::create(['user_id'=>$u->id,'endpoint_hash'=>hash('sha256',$endpoint),'endpoint'=>$endpoint,'public_key'=>str_repeat('A',87),'auth_token'=>str_repeat('B',22),'content_encoding'=>'aes128gcm']);
  $this->actingAs($admin);$input=['amount'=>'5000','plan'=>'select','request_id'=>(string)Str::uuid()];
  $this->send($u,'plans',$input)->assertOk()->assertJsonPath('notification_queued',true)->assertJsonPath('member.wallet.balance',100000)->assertJsonPath('member.wallet.invested',500000)->assertJsonPath('member.wallet.daily_earnings',3000)->assertJsonPath('member.investments.0.term_days',160)->assertJsonPath('member.investments.0.status','active')->assertJsonPath('member.transactions.0.type','plan_grant');
  Queue::assertPushed(SendWebPushNotification::class,fn (SendWebPushNotification $job)=>$job->userId===$u->id&&$job->url==='/#/investments'&&$job->title==='Your investment plan is active'&&str_contains($job->message,'Mom’s Select')&&str_contains($job->message,'activated'));
  $this->send($u,'plans',$input)->assertOk()->assertJsonPath('notification_queued',null);Queue::assertPushed(SendWebPushNotification::class,1);$this->assertDatabaseCount('investment_allocations',1);$inv=InvestmentAllocation::first();$this->assertSame(60,$inv->rate_basis_points);$this->assertSame('0.006000',$inv->daily_interest_rate);
  $this->travel(1)->days();$this->actingAs($u)->getJson('/portal/state')->assertOk()->assertJsonPath('wallet.earnings',3000)->assertJsonPath('wallet.balance',103000)->assertJsonPath('transactions.0.type','interest')->assertJsonPath('transactions.1.type','plan_grant');$this->assertDatabaseHas('wallet_transactions',['user_id'=>$u->id,'type'=>'plan_grant','amount_cents'=>500000]);$this->assertDatabaseCount('admin_actions',1);
 }
 public function test_admin_amount_minimum_plan_and_reason_validation(): void {
  $u=$this->member();$this->actingAs($this->member(true));
  $this->send($u,'plans',['amount'=>'49999.99','plan'=>'prime'])->assertUnprocessable();$this->send($u,'plans',['plan'=>'invalid'])->assertUnprocessable();
  foreach(['-100','1e4','100.001','1000001','99.99'] as $amount)$this->send($u,'funds',['amount'=>$amount])->assertUnprocessable();
  $this->send($u,'funds',['note'=>''])->assertUnprocessable();$this->assertDatabaseCount('admin_actions',0);$this->assertDatabaseCount('investment_allocations',0);
 }
 public function test_directory_details_and_audit_exclude_passwords_and_tokens(): void {
  $u=$this->member();$this->actingAs($this->member(true));
  $this->getJson('/portal/admin/users?search='.urlencode($u->email))->assertOk()->assertJsonCount(1,'items')->assertJsonMissingPath('items.0.password')->assertJsonMissingPath('items.0.remember_token');
  $this->getJson('/portal/admin/users/'.$u->id)->assertOk()->assertJsonPath('user.id',$u->id)->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.remember_token')->assertJsonStructure(['wallet','investments','transactions','wallet_requests','franchise_applications']);
  $this->getJson('/portal/admin/overview')->assertOk()->assertJsonPath('stats.members',1);$this->getJson('/portal/admin/audit')->assertOk()->assertJsonCount(0,'items');
 }
 public function test_simulation_off_blocks_every_admin_financial_mutation(): void {
  $u=$this->member(false,'1000');$admin=$this->member(true);$r=$this->walletRequest($u,'withdraw','100');config(['portal.financial_operations_enabled'=>false]);$this->actingAs($admin);
  $this->review($r)->assertForbidden();$this->review($r,'reject')->assertForbidden();$this->send($u,'funds')->assertForbidden();$this->send($u,'plans',['plan'=>'select'])->assertForbidden();$this->assertDatabaseCount('admin_actions',0);$this->assertSame('pending',$r->fresh()->status);$this->assertSame(10000,Wallet::where('user_id',$u->id)->first()->held_cents);
 }
 public function test_a_pending_request_reference_cannot_also_purchase_a_plan(): void {
  $u=$this->member(false,'10000');$id=(string)Str::uuid();$this->walletRequest($u,'deposit','5000',$id);$this->actingAs($u);
  $this->postJson('/portal/transactions',['type'=>'invest','amount'=>'5000','plan'=>'select','request_id'=>$id])->assertUnprocessable();$this->assertDatabaseCount('investment_allocations',0);
 }
 public function test_admin_mutations_require_the_session_csrf_token(): void {
  $this->app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,fn($app)=>new class($app,$app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken{protected function runningUnitTests(){return false;}});
  $u=$this->member();$this->actingAs($this->member(true));$token=Str::random(40);$this->withSession(['_token'=>$token]);
  $this->send($u,'funds')->assertStatus(419);$this->withHeader('X-CSRF-TOKEN',$token);$this->send($u,'funds')->assertOk();$this->assertDatabaseCount('admin_actions',1);
 }
 public function test_seeding_never_creates_sample_users_balances_or_requests(): void {
  $this->seed(\Database\Seeders\DatabaseSeeder::class);$this->seed(\Database\Seeders\DatabaseSeeder::class);
  foreach(['users','wallets','wallet_requests','wallet_transactions','investment_allocations','admin_actions'] as $table)$this->assertDatabaseCount($table,0);
 }
 public function test_sample_accounts_are_not_seeded_in_production(): void {
  $this->app->detectEnvironment(fn()=> 'production');app(\Database\Seeders\DatabaseSeeder::class)->run();$this->assertDatabaseCount('users',0);
 }
 public function test_admin_creation_hashes_secret_and_refuses_existing_accounts(): void {
  $this->artisan('portal:create-admin',['email'=>'owner@example.test','--name'=>'Portal Owner'])->expectsQuestion('Admin password (at least 12 characters)','A-test-password-2026!')->expectsQuestion('Confirm admin password','A-test-password-2026!')->assertExitCode(0);
  $u=User::where('email','owner@example.test')->firstOrFail();$this->assertTrue($u->is_admin);$this->assertTrue(Hash::check('A-test-password-2026!',$u->password));$this->assertDatabaseHas('wallets',['user_id'=>$u->id]);
  $this->artisan('portal:create-admin',['email'=>'owner@example.test','--name'=>'Another Name'])->assertExitCode(1);$this->assertSame('Portal Owner',$u->fresh()->name);
 }
}
