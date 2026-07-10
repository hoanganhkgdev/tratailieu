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
        Schema::create('monastic_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monastic_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('temple_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('province_id')->nullable()->constrained()->nullOnDelete();

            // I. Định danh & cá nhân cơ bản
            $table->string('full_name');
            $table->string('religious_name')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('ethnicity', 100)->nullable();
            $table->string('nationality', 100)->nullable();
            $table->string('id_number', 30)->nullable();
            $table->date('id_issued_date')->nullable();
            $table->string('id_issued_place')->nullable();
            $table->string('hometown')->nullable();
            $table->string('permanent_address')->nullable();
            $table->string('current_address')->nullable();
            $table->string('monastic_cert_number', 100)->nullable();
            $table->date('monastic_cert_date')->nullable();

            // II. Hành đạo & chuyên môn tôn giáo
            $table->string('religion', 100)->nullable();
            $table->string('religious_org')->nullable();
            $table->string('sect')->nullable();
            // Phân loại cho phép chọn nhiều (chức sắc/chức việc/nhà tu hành).
            $table->json('classification')->nullable();
            $table->text('current_position')->nullable();
            $table->date('ordination_date')->nullable();
            $table->text('concurrent_position')->nullable();
            $table->string('activity_scope')->nullable();
            $table->text('notes')->nullable();

            // III. Đào tạo
            $table->string('education_level')->nullable();
            $table->string('professional_qualification')->nullable();
            $table->string('religious_education_level')->nullable();
            $table->text('training_institutions')->nullable();
            $table->string('languages')->nullable();

            // IV. Hoạt động & bổ nhiệm
            $table->text('activity_history')->nullable();
            $table->text('commendation_discipline')->nullable();
            $table->text('violations')->nullable();
            $table->string('congress_term')->nullable();

            // V. Liên hệ & tình trạng
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('status')->nullable();

            $table->timestamps();

            $table->index(['temple_id']);
            $table->index(['province_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monastic_profiles');
    }
};
