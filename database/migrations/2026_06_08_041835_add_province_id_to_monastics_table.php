<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('monastics', function (Blueprint $table) {
            $table->foreignId('province_id')->nullable()->after('temple_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monastics', function (Blueprint $table) {
            $table->dropConstrainedForeignId('province_id');
        });
    }
};
