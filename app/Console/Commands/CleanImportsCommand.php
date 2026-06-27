<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanImportsCommand extends Command
{
    protected $signature = 'imports:clean';
    protected $description = 'Xóa thư mục imports tạm sau khi đã import xong';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $dirs = collect($disk->directories('imports'))
            ->reject(fn ($d) => in_array(basename($d), ['temp', 'bulk']));

        if ($dirs->isEmpty()) {
            $this->info('Không có thư mục tạm nào cần xóa.');
            return self::SUCCESS;
        }

        foreach ($dirs as $dir) {
            $fileCount = count($disk->allFiles($dir));
            if ($fileCount === 0) {
                $disk->deleteDirectory($dir);
                $this->line("Đã xóa: {$dir} (trống)");
            } else {
                $this->warn("Bỏ qua: {$dir} (còn {$fileCount} file chưa xử lý)");
            }
        }

        $tmpFiles = \Illuminate\Support\Facades\Storage::files('livewire-tmp');
        if (count($tmpFiles) > 0) {
            \Illuminate\Support\Facades\Storage::delete($tmpFiles);
            $this->line("Đã xóa " . count($tmpFiles) . " file tạm Livewire.");
        }

        $this->info('Dọn dẹp xong.');
        return self::SUCCESS;
    }
}
