<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Один мастер может быть приведён только один раз — это уже
 * обеспечивает ReferralService::registerReferral() через
 * firstOrCreate по referred_master_id. Индекс добавляет ту же
 * гарантию на уровне БД (защита от гонки конкурентных запросов).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->unique('referred_master_id');
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropUnique(['referred_master_id']);
        });
    }
};
