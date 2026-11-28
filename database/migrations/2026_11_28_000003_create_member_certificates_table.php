<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by')->constrained('users')->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('certificate_number', 80);
            $table->string('holder_name', 120);
            $table->string('plan_name', 120);
            $table->date('indicated_date');
            $table->string('sender_name', 80);
            $table->string('attachment_path');
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->timestamp('sent_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_certificates');
    }
};
