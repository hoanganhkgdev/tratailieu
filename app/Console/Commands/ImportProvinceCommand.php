<?php

namespace App\Console\Commands;

use App\Jobs\ImportTempleFileJob;
use App\Models\Province;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ImportProvinceCommand extends Command
{
    protected $signature = 'import:province
        {province : Tên tỉnh hoặc ID}
        {--path= : Đường dẫn thư mục trên disk public (mặc định: imports/{slug-tỉnh})}';

    protected $description = 'Import hàng loạt file tài liệu tự viện từ thư mục theo tỉnh';

    public function handle(): int
    {
        $input = $this->argument('province');
        $province = is_numeric($input)
            ? Province::find($input)
            : Province::where('name', 'like', "%{$input}%")->first();

        if (! $province) {
            $this->error("Không tìm thấy tỉnh: {$input}");
            return self::FAILURE;
        }

        $path = $this->option('path') ?? 'imports/' . \Illuminate\Support\Str::slug($province->name);
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            $this->error("Thư mục không tồn tại: storage/app/public/{$path}");
            $this->info("Hãy copy file vào: " . $disk->path($path));
            return self::FAILURE;
        }

        $files = collect($disk->files($path))
            ->filter(fn ($f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['pdf', 'docx']))
            ->values();

        if ($files->isEmpty()) {
            $this->warn("Không tìm thấy file PDF/DOCX nào trong thư mục.");
            return self::FAILURE;
        }

        $this->info("Tỉnh: {$province->name}");
        $this->info("Thư mục: {$path}");
        $this->info("Tìm thấy: {$files->count()} file");

        if (! $this->confirm("Bắt đầu import {$files->count()} file?")) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($files->count());
        $bar->start();

        foreach ($files as $file) {
            ImportTempleFileJob::dispatch($file, $province->id);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Đã đẩy {$files->count()} file vào queue. Chạy queue worker để xử lý.");
        $this->info("Theo dõi: tail -f storage/logs/laravel.log | grep Import");

        return self::SUCCESS;
    }
}
