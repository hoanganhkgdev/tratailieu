<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lưu số token và chi phí ước tính mỗi lần gọi AI trích xuất, để thấy được
     * chi phí ngay trong app — tránh lặp lại việc "không biết đang tốn bao nhiêu"
     * như hồi dùng Gemini.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedInteger('ai_input_tokens')->nullable()->after('extracted_json');
            $table->unsignedInteger('ai_output_tokens')->nullable()->after('ai_input_tokens');
            $table->decimal('ai_cost_usd', 10, 6)->nullable()->after('ai_output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['ai_input_tokens', 'ai_output_tokens', 'ai_cost_usd']);
        });
    }
};
