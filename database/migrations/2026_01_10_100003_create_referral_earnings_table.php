<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица для существующей модели ReferralEarning.
 *
 * Миграции в шаблоне не было, хотя модель и PaymentObserver уже
 * пишут в неё начисления. Схема — ровно тот набор полей, который
 * создаёт PaymentObserver::created().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_earnings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('referrer_master_id')->constrained('masters')->cascadeOnDelete();
            $table->foreignId('referred_master_id')->constrained('masters')->cascadeOnDelete();
            $table->foreignId('referral_id')->constrained('referrals')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->unsignedInteger('payment_amount');
            $table->unsignedInteger('amount');
            $table->unsignedSmallInteger('percent');
            $table->string('status')->default('pending');

            $table->index(['referrer_master_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_earnings');
    }
};
