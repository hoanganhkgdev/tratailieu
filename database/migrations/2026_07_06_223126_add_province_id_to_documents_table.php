<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tỉnh do admin chọn sẵn khi upload — ưu tiên hơn tỉnh AI tự đoán từ nội dung
     * file, tránh trường hợp AI nhầm tên huyện/xã cũ thành tên tỉnh.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('province_id')->nullable()->after('uploaded_by')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('province_id');
        });
    }
};
