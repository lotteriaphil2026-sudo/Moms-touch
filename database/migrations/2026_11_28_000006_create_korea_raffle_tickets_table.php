<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('korea_raffle_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ticket_code', 40)->unique();
            $table->date('claim_date');
            $table->timestamps();
            $table->unique(['user_id', 'claim_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('korea_raffle_tickets');
    }
};
