<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('slug');
            $table->enum('type', ['chua', 'tu_vien', 'tinh_xa', 'thien_vien', 'tinh_that'])->default('chua');
            $table->string('address')->nullable();
            $table->string('head_monk')->nullable();
            $table->string('phone', 20)->nullable();
            // FK thêm sau ở migration add_latest_document_foreign_to_temples_table,
            // vì bảng documents tham chiếu ngược lại temples nên phải tạo temples trước.
            $table->unsignedBigInteger('latest_document_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['province_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temples');
    }
};
