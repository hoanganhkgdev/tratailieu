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
        Schema::create('monastics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temple_id')->nullable()->constrained()->nullOnDelete();
            $table->string('photo')->nullable();

            // I. Thông tin định danh và cá nhân cơ bản
            $table->string('full_name');                  // Họ và tên khai sinh
            $table->string('religious_name')->nullable(); // Tên trong tôn giáo / Pháp danh
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['nam', 'nu']);
            $table->string('ethnicity')->nullable();      // Dân tộc
            $table->string('nationality')->default('Việt Nam');
            $table->enum('id_type', ['cmnd', 'cccd', 'ho_chieu', 'chung_nhan_tang_ni', 'khac'])->nullable();
            $table->string('id_number')->nullable();
            $table->date('id_issued_date')->nullable();
            $table->string('id_issued_place')->nullable();
            $table->string('hometown')->nullable();          // Quê quán
            $table->string('permanent_address')->nullable(); // Địa chỉ thường trú
            $table->string('current_address')->nullable();   // Nơi ở hiện tại
            $table->string('monastic_cert_number')->nullable(); // Số chứng nhận Tăng ni
            $table->date('monastic_cert_date')->nullable();

            // II. Thông tin hành đạo và chuyên môn tôn giáo
            $table->string('religion')->default('Phật giáo');
            $table->string('religious_organization')->nullable();
            $table->string('sect')->nullable();           // Hệ phái / Dòng tu
            $table->json('classifications')->nullable();  // Chức sắc / Chức việc / Nhà tu hành
            $table->string('rank')->nullable();           // Phẩm trật (Hòa thượng, Ni sư...)
            $table->string('current_position')->nullable(); // Chức vụ/Phẩm vị hiện tại
            $table->date('appointment_date')->nullable();   // Ngày thụ phong/bổ nhiệm
            $table->string('concurrent_position')->nullable();
            $table->enum('activity_scope', ['toan_quoc', 'mot_so_tinh', 'mot_tinh'])->nullable();
            $table->string('activity_scope_detail')->nullable();
            $table->text('notes')->nullable();

            // III. Quá trình đào tạo
            $table->string('education_level')->nullable();           // Trình độ học vấn phổ thông
            $table->string('professional_qualification')->nullable(); // Trình độ chuyên môn
            $table->string('buddhist_education_level')->nullable();   // Trình độ tu học
            $table->text('training_institutions')->nullable();        // Cơ sở đào tạo tôn giáo
            $table->string('languages')->nullable();                  // Ngoại ngữ / tiếng dân tộc

            // V. Liên hệ và tình trạng
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->enum('status', [
                'dang_hoat_dong', 'huu_tri', 'cach_chuc', 'hoan_tuc', 'tan_xuat', 'da_chet',
            ])->default('dang_hoat_dong');
            $table->date('death_date')->nullable();

            $table->timestamps();
        });

        // IV. Quá trình hoạt động và bổ nhiệm/bầu cử/suy cử (lịch sử nhiều giai đoạn)
        Schema::create('monastic_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monastic_id')->constrained()->cascadeOnDelete();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->string('place')->nullable();    // Nơi hành đạo/hoạt động
            $table->string('position')->nullable(); // Chức vụ đảm nhận
            $table->text('commendation')->nullable(); // Khen thưởng/kỷ luật
            $table->text('violation')->nullable();    // Khiếu kiện, vi phạm
            $table->string('term_period')->nullable(); // Nhiệm kỳ đại hội (vd: 2020-2025)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monastic_activities');
        Schema::dropIfExists('monastics');
    }
};
