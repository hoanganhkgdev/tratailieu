<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * AI thỉnh thoảng trả về nhiều số điện thoại gộp chung một chuỗi
     * (vd "0767116918, 0816786717"), vượt quá varchar(20) ban đầu.
     * SQLite (dùng trong test) không có kiểu VARCHAR có giới hạn độ dài
     * thật sự nên không cần ALTER ở đó.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE temples MODIFY phone VARCHAR(50) NULL');
        DB::statement('ALTER TABLE monastics MODIFY phone VARCHAR(50) NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE temples MODIFY phone VARCHAR(20) NULL');
        DB::statement('ALTER TABLE monastics MODIFY phone VARCHAR(20) NULL');
    }
};
