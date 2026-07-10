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
        Schema::create('monastic_documents', function (Blueprint $table) {
            $table->id();
            // Tự viện/tỉnh chỉ xác định được SAU khi AI đọc nội dung phiếu (khác với
            // documents của chùa — nơi admin chọn tỉnh trước khi upload) vì mỗi file ở
            // đây là 1 phiếu cá nhân, đường dẫn thư mục không đảm bảo đúng tỉnh của
            // NƠI HÀNH ĐẠO ghi trong phiếu.
            $table->foreignId('temple_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('province_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->enum('file_type', ['pdf', 'docx']);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->json('extracted_json')->nullable();
            $table->enum('status', ['pending', 'processing', 'ready', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('ai_input_tokens')->nullable();
            $table->unsignedInteger('ai_output_tokens')->nullable();
            $table->decimal('ai_cost_usd', 10, 6)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monastic_documents');
    }
};
