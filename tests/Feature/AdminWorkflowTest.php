<?php
namespace Tests\Feature;
use App\Jobs\SendWebPushNotification;
use App\Models\{InvestmentAllocation, PushSubscription, User, Wallet, WalletRequest, WithdrawalAccount};
use App\Services\{WalletService, WalletRequestService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Hash, Queue, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;
class AdminWorkflowTest extends TestCase
{
 use RefreshDatabase;
 protected function setUp(): void { parent::setUp();config(['portal.financial_operations_enabled'=>true,'portal.registration_enabled'=>true,'portal.deposit_qr_demo_mode'=>false]);Storage::fake('local'); }
 private function member(bool $admin=false,string $funds='0'): User {
  $u=User::factory()->create();$u->forceFill(['is_admin'=>$admin])->save();Wallet::create(['user_id'=>$u->id]);
  if($funds!=='0')app(WalletService::class)->transact($u,'deposit',$funds,null,(string)Str::uuid());return $u;
 }
 private function walletRequest(User $u,string $type,string $amount,?string $id=null): WalletRequest {$account=$type==='withdraw'?WithdrawalAccount::create(['user_id'=>$u->id,'type'=>'bank','provider'=>'BPI','account_holder'=>$u->name,'account_number'=>'1234567890']):null;return app(WalletRequestService::class)->submit($u,$type,$amount,$id??(string)Str::uuid(),'Member request',null,$account);}
 private function review(WalletRequest $r,string $decision='approve'){return $this->postJson('/portal/admin/requests/'.$r->id.'/review',['decision'=>$decision,'note'=>'Reviewed and checked']);}
 private function submitDeposit(array $payload){return $this->post('/portal/transactions',[...$payload,'deposit_plan'=>$payload['deposit_plan']??'select','receipt'=>$this->receiptUpload()],['Accept'=>'application/json']);}
 private function receiptUpload(): UploadedFile{return UploadedFile::fake()->createWithContent('receipt.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lGkAAAAASUVORK5CYII='));}
 private function send(User $u,string $kind,array $data=[]){return $this->postJson('/portal/admin/users/'.$u->id.'/'.$kind,[...['amount'=>'5000.25','note'=>'Member credit','request_id'=>(string)Str::uuid()],...$data]);}
 public function test_guests_and_members_cannot_use_any_admin_endpoint(): void {
  $u=$this->member();$r=$this->walletRequest($u,'deposit','5000');
  $urls=['/portal/admin/overview','/portal/admin/users','/portal/admin/users/'.$u->id,'/portal/admin/deposit-users','/portal/admin/receipt-logs','/portal/admin/requests','/portal/admin/audit'];
  foreach($urls as $url)$this->getJson($url)->assertUnauthorized();$this->review($r)->assertUnauthorized();$this->send($u,'funds')->assertUnauthorized();
  $this->postJson('/portal/admin/users/'.$u->id.'/classification',['classification'=>'dummy','secret_key'=>str_repeat('x',32),'request_id'=>(string)Str::uuid()])->assertUnauthorized();
  $this->postJson('/portal/admin/users/'.$u->id.'/referral-preview',['preview_type'=>'special'])->assertUnauthorized();
  $this->postJson('/portal/admin/users/'.$u->id.'/label',['label'=>'VIP'])->assertUnauthorized();
  $this->actingAs($u);foreach($urls as $url)$this->getJson($url)->assertForbidden();$this->getJson('/portal/admin/users/999999')->assertForbidden();$this->review($r)->assertForbidden();
  $this->postJson('/portal/admin/users/'.$u->id.'/label',['label'=>'VIP'])->assertForbidden();
  $this->send($u,'funds',['is_admin'=>true])->assertForbidden();$this->send($u,'plans',['plan'=>'select','is_admin'=>true])->assertForbidden();$this->assertDatabaseCount('admin_actions',0);
 }
 public function test_admin_assigns_one_referral_preview_type_per_member(): void {
  $member=$this->member();$referralCode=$member->referral_code;$admin=$this->member(true);$this->actingAs($admin);
  $url='/portal/admin/users/'.$member->id.'/referral-preview';
  $this->postJson($url,['preview_type'=>'special'])->assertOk()->assertJsonPath('referral_preview_type','special')->assertJsonPath('message','Special preview link assigned.');
  $this->postJson($url,['preview_type'=>'special'])->assertOk()->assertJsonPath('message','This member already uses that referral preview.');
  $this->assertDatabaseHas('users',['id'=>$member->id,'referral_code'=>$referralCode,'referral_preview_type'=>'special']);
  $this->assertDatabaseHas('admin_actions',['actor_id'=>$admin->id,'user_id'=>$member->id,'action'=>'referral_preview_assigned']);
  $this->assertDatabaseCount('admin_actions',1);
  $this->actingAs($member->fresh())->getJson('/portal/state')->assertOk()->assertJsonPath('user.referral_code',$referralCode)->assertJsonPath('user.referral_preview_type','special');
  $this->actingAs($admin);
  $this->postJson($url,['preview_type'=>'standard'])->assertOk()->assertJsonPath('referral_preview_type','standard')->assertJsonPath('message','Standard referral preview assigned.');
  $this->assertDatabaseHas('users',['id'=>$member->id,'referral_code'=>$referralCode,'referral_preview_type'=>'standard']);
  $this->assertDatabaseCount('admin_actions',2);
  $this->actingAs($member->fresh())->getJson('/portal/state')->assertOk()->assertJsonPath('user.referral_preview_type','standard');
 }
 public function test_admin_can_set_and_clear_a_member_label(): void {
  $member=$this->member();$admin=$this->member(true);$this->actingAs($admin);
  $url='/portal/admin/users/'.$member->id.'/label';
  $this->postJson($url,['label'=>'  VIP Member  '])->assertOk()->assertJsonPath('admin_label','VIP Member')->assertJsonPath('message','Member label updated.');
  $this->assertDatabaseHas('users',['id'=>$member->id,'admin_label'=>'VIP Member']);
  $this->postJson($url,['label'=>'VIP Member'])->assertOk()->assertJsonPath('message','Member label is unchanged.');
  $this->getJson('/portal/admin/users?search='.urlencode($member->email))->assertOk()->assertJsonPath('items.0.admin_label','VIP Member');
  $this->getJson('/portal/admin/users/'.$member->id)->assertOk()->assertJsonPath('user.admin_label','VIP Member');
  $this->assertDatabaseCount('admin_actions',1);
  $this->postJson($url,['label'=>null])->assertOk()->assertJsonPath('admin_label',null)->assertJsonPath('message','Member label removed.');
  $this->assertDatabaseHas('users',['id'=>$member->id,'admin_label'=>null]);
  $this->assertDatabaseCount('admin_actions',2);
  $this->postJson('/portal/admin/users/'.$admin->id.'/label',['label'=>'Admin'])->assertUnprocessable();
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
  $this->submitDeposit(['type'=>'deposit','amount'=>'5000','payment_method'=>'bpi','request_id'=>(string)Str::uuid(),'user_id'=>$first->id])->assertOk()->assertJsonCount(1,'wallet_requests');
  $this->assertSame(1,WalletRequest::where('user_id',$first->id)->count());$this->assertSame(1,WalletRequest::where('user_id',$second->id)->count());
 }
 public function test_demo_deposits_are_excluded_from_admin_review_lists(): void {
  $member=$this->member();$admin=$this->member(true);
  config(['portal.deposit_qr_demo_mode'=>true]);
  $demo=$this->actingAs($member)->submitDeposit(['type'=>'deposit','amount'=>'5000','payment_method'=>'bpi','request_id'=>(string)Str::uuid()])->assertOk()->assertJsonPath('submitted_request.test_only',true)->json('submitted_request');
  config(['portal.deposit_qr_demo_mode'=>false]);
  $real=$this->submitDeposit(['type'=>'deposit','amount'=>'5000','payment_method'=>'bpi','request_id'=>(string)Str::uuid()])->assertOk()->assertJsonPath('submitted_request.test_only',false)->json('submitted_request');
  $this->actingAs($admin);
  $this->getJson('/portal/admin/overview')->assertOk()->assertJsonPath('stats.pending_deposits',1)->assertJsonCount(1,'requests')->assertJsonPath('requests.0.id',$real['id']);
  $this->getJson('/portal/admin/requests?status=all')->assertOk()->assertJsonCount(1,'items')->assertJsonPath('items.0.id',$real['id']);
  $this->getJson('/portal/admin/deposit-users?status=all')->assertOk()->assertJsonPath('total',1);
  $this->getJson('/portal/admin/users/'.$member->id)->assertOk()->assertJsonCount(1,'wallet_requests')->assertJsonPath('wallet_requests.0.id',$real['id']);
  $this->assertDatabaseHas('wallet_requests',['id'=>$demo['id'],'test_only'=>true]);
 }
 public function test_deposit_is_pending_until_approval_and_replays_do_not_duplicate_credit(): void {
  $u=$this->member();$admin=$this->member(true);$id=(string)Str::uuid();$this->actingAs($u);
  $payload=['type'=>'deposit','amount'=>'5000.25','payment_method'=>'bpi','request_id'=>$id];
  $this->submitDeposit([...$payload,'status'=>'approved','reviewed_by'=>$admin->id])->assertOk()->assertJsonPath('wallet.balance',0)->assertJsonPath('wallet_requests.0.status','pending');
  $this->submitDeposit($payload)->assertOk();$this->submitDeposit([...$payload,'amount'=>'1001'])->assertUnprocessable();
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
  $referrer=$this->member();$u=$this->member();$u->referrer()->associate($referrer);$u->save();$this->actingAs($this->member(true));
  $this->getJson('/portal/admin/users?search='.urlencode($u->email))->assertOk()->assertJsonCount(1,'items')->assertJsonPath('items.0.referrer.name',$referrer->name)->assertJsonPath('items.0.referrer.email',$referrer->email)->assertJsonMissingPath('items.0.password')->assertJsonMissingPath('items.0.remember_token');
  $this->getJson('/portal/admin/users/'.$u->id)->assertOk()->assertJsonPath('user.id',$u->id)->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.remember_token')->assertJsonStructure(['wallet','investments','transactions','wallet_requests','franchise_applications']);
  $this->getJson('/portal/admin/overview')->assertOk()->assertJsonPath('stats.members',2);$this->getJson('/portal/admin/audit')->assertOk()->assertJsonCount(0,'items');
 }
 public function test_member_presence_is_private_and_admin_directory_only_receives_online_status(): void {
  $member=$this->member();$admin=$this->member(true);
  $this->postJson('/portal/presence')->assertUnauthorized();
  $this->actingAs($member)->postJson('/portal/presence')->assertOk()->assertJsonPath('ok',true);
  $this->assertNotNull($member->fresh()->last_seen_at);
  $this->actingAs($admin)->getJson('/portal/admin/users?search='.urlencode($member->email))->assertOk()
   ->assertJsonPath('items.0.is_online',true)->assertJsonMissingPath('items.0.last_seen_at');
  $member->forceFill(['last_seen_at'=>now()->subMinutes(3)])->save();
  $this->getJson('/portal/admin/users?search='.urlencode($member->email))->assertOk()->assertJsonPath('items.0.is_online',false);
 }
 public function test_admin_classification_requires_the_configured_secret_keeps_dummy_in_directory_and_preserves_referrals(): void {
  $member=$this->member();$admin=$this->member(true);$this->actingAs($admin);$requestId=(string)Str::uuid();
  config(['portal.admin_member_classification_key'=>str_repeat('s',48)]);
  $this->postJson('/portal/admin/users/'.$member->id.'/classification',['classification'=>'dummy','secret_key'=>str_repeat('w',48),'request_id'=>$requestId])->assertForbidden();
  $this->assertFalse($member->fresh()->is_dummy);$this->assertDatabaseCount('admin_actions',0);
  $this->postJson('/portal/admin/users/'.$member->id.'/classification',['classification'=>'dummy','secret_key'=>str_repeat('s',48),'request_id'=>$requestId])
   ->assertOk()->assertJsonPath('classification','dummy')->assertJsonPath('is_dummy',true)->assertJsonMissingPath('secret_key');
  $this->getJson('/portal/admin/overview')->assertOk()->assertJsonPath('stats.members',0);
  $directoryResponse=$this->getJson('/portal/admin/users')->assertOk()
   ->assertJsonPath('total',2)
   ->assertJsonPath('real_total',1);
  $directory=$directoryResponse->json('items');
  $this->assertContains($member->id,array_column($directory,'id'));
  $this->assertTrue(collect($directory)->firstWhere('id',$member->id)['is_dummy']);
  $this->getJson('/portal/admin/users/'.$member->id)->assertOk()->assertJsonPath('user.is_dummy',true);
  $this->actingAs($member)->getJson('/portal/state')->assertOk()->assertJsonMissingPath('user.is_dummy')->assertJsonPath('user.referral_code',$member->referral_code);
  $this->postJson('/portal/logout')->assertOk();
  $this->postJson('/portal/register',['name'=>'Referred Member','email'=>'referred@example.test','phone'=>'+639171234567','date_of_birth'=>'1990-01-01','address'=>'123 Example Street, Makati City','gender'=>'Other','marital_status'=>'Single','password'=>'Example-password12!','password_confirmation'=>'Example-password12!','referral_code'=>$member->referral_code])
   ->assertOk()->assertJsonPath('user.referrer.id',$member->id);
  $this->assertDatabaseHas('users',['id'=>$member->id,'is_dummy'=>true]);
  $this->assertDatabaseHas('admin_actions',['actor_id'=>$admin->id,'user_id'=>$member->id,'action'=>'member_classified','note'=>'Classified member as dummy.']);
  $this->actingAs($admin)->postJson('/portal/admin/users/'.$member->id.'/classification',['classification'=>'real','secret_key'=>str_repeat('s',48),'request_id'=>(string)Str::uuid()])
   ->assertOk()->assertJsonPath('is_dummy',false);
  $this->getJson('/portal/admin/users')->assertOk()->assertJsonPath('total',3);
 }
 public function test_dummy_members_are_excluded_from_admin_overview_but_visible_in_request_queues(): void {
  $member=$this->member(false,'10000');$dummy=$this->member(false,'10000');$admin=$this->member(true);
  $memberDeposit=$this->walletRequest($member,'deposit','5000');$dummyDeposit=$this->walletRequest($dummy,'deposit','5000');
  $memberWithdrawal=$this->walletRequest($member,'withdraw','1000');$dummyWithdrawal=$this->walletRequest($dummy,'withdraw','1000');
  config(['portal.admin_member_classification_key'=>str_repeat('s',48)]);
  $this->actingAs($admin)->postJson('/portal/admin/users/'.$dummy->id.'/classification',[
   'classification'=>'dummy','secret_key'=>str_repeat('s',48),'request_id'=>(string)Str::uuid(),
  ])->assertOk()->assertJsonPath('is_dummy',true);

  $overview=$this->getJson('/portal/admin/overview')->assertOk()
   ->assertJsonPath('stats.members',1)
   ->assertJsonPath('stats.wallet_total',900000)
   ->assertJsonPath('stats.held_total',100000)
   ->assertJsonPath('stats.pending_deposits',1)
   ->assertJsonPath('stats.pending_withdrawals',1)
   ->assertJsonCount(2,'requests');
  $this->assertEqualsCanonicalizing(
   [$memberDeposit->id,$memberWithdrawal->id],
   array_column($overview->json('requests'),'id')
  );

  $deposits=$this->getJson('/portal/admin/requests?type=deposit&status=all')->assertOk()->assertJsonCount(2,'items')->json('items');
  $withdrawals=$this->getJson('/portal/admin/requests?type=withdraw&status=all')->assertOk()->assertJsonCount(2,'items')->json('items');
  $this->assertEqualsCanonicalizing(
   [$member->id,$dummy->id],
   array_column(array_column($deposits,'user'),'id')
  );
  $this->assertEqualsCanonicalizing(
   [$member->id,$dummy->id],
   array_column(array_column($withdrawals,'user'),'id')
  );
  $depositUsers=$this->getJson('/portal/admin/deposit-users?status=all')->assertOk()->assertJsonPath('total',2)->json('items');
  $this->assertEqualsCanonicalizing(
   [$member->id,$dummy->id],
   array_column(array_column($depositUsers,'user'),'id')
  );
 }
 public function test_admin_classification_fails_closed_when_secret_is_unconfigured_and_cannot_target_an_admin(): void {
  $member=$this->member();$admin=$this->member(true);$this->actingAs($admin);
  $body=['classification'=>'dummy','secret_key'=>str_repeat('s',48),'request_id'=>(string)Str::uuid()];
  $this->postJson('/portal/admin/users/'.$member->id.'/classification',$body)->assertStatus(503);
  config(['portal.admin_member_classification_key'=>str_repeat('s',48)]);
  $this->postJson('/portal/admin/users/'.$admin->id.'/classification',[...$body,'request_id'=>(string)Str::uuid()])->assertUnprocessable();
  $this->assertFalse($member->fresh()->is_dummy);$this->assertDatabaseCount('admin_actions',0);
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
