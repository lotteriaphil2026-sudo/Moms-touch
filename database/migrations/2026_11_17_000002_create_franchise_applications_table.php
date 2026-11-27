<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('franchise_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20)->unique();
            $table->uuid('request_id');
            $table->string('request_fingerprint', 64);
            $table->string('contact_name', 80);
            $table->string('contact_email', 254);
            $table->string('phone', 30);
            $table->string('country', 80);
            $table->string('city', 100);
            $table->string('region', 100);
            $table->string('site_status', 20);
            $table->unsignedBigInteger('budget_cents')->nullable();
            $table->string('target_opening', 7)->nullable();
            $table->text('experience')->nullable();
            $table->string('status', 20)->default('submitted');
            $table->timestamp('consent_at');
            $table->timestamps();
            $table->unique(['user_id', 'request_id']);
            $table->index(['user_id', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('franchise_applications'); }
};
