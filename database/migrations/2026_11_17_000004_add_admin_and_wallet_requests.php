<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('is_admin')->default(false));
        Schema::table('wallets', fn (Blueprint $t) => $t->unsignedBigInteger('held_cents')->default(0));
        Schema::create('wallet_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->uuid('request_id');
            $t->string('request_fingerprint', 64);
            $t->string('type', 16);
            $t->unsignedBigInteger('amount_cents');
            $t->string('status', 16)->default('pending');
            $t->text('member_note')->nullable();
            $t->text('review_note')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'request_id']);
            $t->index(['status', 'type', 'created_at']);
        });
        Schema::create('admin_actions', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('wallet_request_id')->nullable()->constrained()->restrictOnDelete();
            $t->uuid('request_id');
            $t->string('request_fingerprint', 64);
            $t->string('action', 32);
            $t->unsignedBigInteger('amount_cents');
            $t->text('note');
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->unique(['actor_id', 'request_id']);
            $t->index(['user_id', 'created_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('admin_actions');
        Schema::dropIfExists('wallet_requests');
        Schema::table('wallets', fn (Blueprint $t) => $t->dropColumn('held_cents'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_admin'));
    }
};
