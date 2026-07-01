<?php

namespace App\Console\Commands;

use App\Jobs\ImportTempleFileJob;
use App\Models\Province;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReImportAllCommand extends Command
{
    protected $signature = 'import:reimport-all';
    protected $description = 'Xóa data cũ và re-import tất cả file từ R2';

    public function handle(): int
    {
        $this->info('Xóa data cũ...');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('document_chunks')->truncate();
        DB::table('documents')->truncate();
        DB::table('monastic_activities')->truncate();
        DB::table('monastics')->truncate();
        DB::table('temples')->truncate();
        DB::table('jobs')->truncate();
        DB::table('failed_jobs')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->info('Đã xóa data cũ.');

        $disk = Storage::disk('public');
        $total = 0;

        // File đã import vào tu-vien/ → copy về imports/staging rồi re-dispatch
        $this->info('Đang quét tu-vien/ trên R2...');
        foreach ($disk->directories('tu-vien') as $provinceDir) {
            $provinceSlug = basename($provinceDir);
            $province = $this->matchProvince($provinceSlug);
            if (! $province) {
                $this->warn("Không tìm thấy tỉnh: {$provinceSlug}");
                continue;
            }

            $files = collect($disk->allFiles($provinceDir))
                ->filter(fn ($f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['pdf', 'docx']));

            foreach ($files as $file) {
                ImportTempleFileJob::dispatch($file, $province->id);
            }

            $this->line("{$province->name}: {$files->count()} files");
            $total += $files->count();
        }

        // File còn trong staging imports/
        $this->info('Đang quét imports/ (staging) trên R2...');
        foreach ($disk->directories('imports') as $dir) {
            $rawSlug = basename($dir);
            $provinceSlug = preg_replace('/_[a-f0-9]{5,}$/', '', $rawSlug);
            $province = $this->matchProvince($provinceSlug);
            if (! $province) continue;

            $files = collect($disk->allFiles($dir))
                ->filter(fn ($f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['pdf', 'docx']));

            foreach ($files as $file) {
                ImportTempleFileJob::dispatch($file, $province->id);
            }

            if ($files->count() > 0) {
                $this->line("Staging {$province->name}: {$files->count()} files");
                $total += $files->count();
            }
        }

        $this->newLine();
        $this->info("Tổng: {$total} files đã được đưa vào queue.");
        $this->info('Khởi động supervisor để xử lý.');

        return self::SUCCESS;
    }

    private function matchProvince(string $slug): ?Province
    {
        // Thử match trực tiếp trước
        $name = str_replace('-', ' ', $slug);
        return Province::whereRaw('LOWER(name) LIKE ?', ['%' . $name . '%'])
            ->orWhereRaw('LOWER(name) LIKE ?', ['%' . str_replace('-', '%', $slug) . '%'])
            ->first();
    }
}
