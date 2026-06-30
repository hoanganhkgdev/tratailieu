<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateToR2Command extends Command
{
    protected $signature = 'storage:migrate-to-r2 {--delete-local : Xóa file local sau khi upload thành công}';
    protected $description = 'Upload toàn bộ file trong storage/app/public lên R2 (disk public hiện tại)';

    public function handle(): int
    {
        $localDisk = Storage::disk('local_public');
        $remoteDisk = Storage::disk('public');

        $files = collect($localDisk->allFiles());

        if ($files->isEmpty()) {
            $this->info('Không có file nào cần migrate.');
            return self::SUCCESS;
        }

        $this->info("Tìm thấy {$files->count()} file. Bắt đầu upload lên R2...");
        $bar = $this->output->createProgressBar($files->count());
        $bar->start();

        $success = 0;
        $failed = 0;

        foreach ($files as $file) {
            try {
                if (! $remoteDisk->exists($file)) {
                    $remoteDisk->put($file, $localDisk->get($file));
                }

                if ($this->option('delete-local')) {
                    $localDisk->delete($file);
                }

                $success++;
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->error("Lỗi: {$file} — {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Hoàn tất: {$success} thành công, {$failed} lỗi.");

        return self::SUCCESS;
    }
}
