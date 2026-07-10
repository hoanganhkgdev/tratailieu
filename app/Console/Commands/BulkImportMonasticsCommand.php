<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMonasticDocumentJob;
use App\Models\MonasticDocument;
use App\Models\Province;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BulkImportMonasticsCommand extends Command
{
    /**
     * Giống hệt cấu trúc temples:bulk-import — mỗi file ở đây là 1 phiếu CÁ NHÂN
     * (Phiếu số 3), không phải hồ sơ tự viện. Tỉnh/tự viện thật sự dùng để lưu vào
     * DB là tỉnh AI đọc được TỪ NỘI DUNG phiếu (field "province_name"), KHÔNG phải
     * tên thư mục — thư mục chỉ dùng để tổ chức đường dẫn lưu trên R2 cho gọn.
     */
    protected $signature = 'tang-ni:bulk-import
        {path : Đường dẫn thư mục trên server}
        {province? : Tên tỉnh/thành. Bỏ trống để coi "path" là thư mục cha chứa nhiều thư mục con, mỗi thư mục con đặt tên theo 1 tỉnh/thành}';

    protected $description = 'Upload hàng loạt phiếu hồ sơ tăng ni từ thư mục local lên R2 và đưa vào hàng đợi xử lý AI';

    public function handle(): int
    {
        $path = rtrim($this->argument('path'), '/');

        if (! is_dir($path)) {
            $this->error("Không tìm thấy thư mục: {$path}");

            return self::FAILURE;
        }

        if ($this->argument('province')) {
            $province = Province::findByNameOrAlias($this->argument('province'));

            if (! $province) {
                $this->error('Không tìm thấy tỉnh/thành: '.$this->argument('province'));

                return self::FAILURE;
            }

            return $this->importFolder($path, $province);
        }

        $subfolders = collect(scandir($path))
            ->reject(fn ($f) => in_array($f, ['.', '..', '.DS_Store']))
            ->filter(fn ($f) => is_dir("{$path}/{$f}"))
            ->values();

        if ($subfolders->isEmpty()) {
            $this->error('Không có tỉnh nào được truyền và không tìm thấy thư mục con nào bên trong. Truyền tên tỉnh làm tham số thứ 2, hoặc tạo thư mục con đặt tên theo tỉnh.');

            return self::FAILURE;
        }

        $exitCode = self::SUCCESS;

        foreach ($subfolders as $folderName) {
            $province = Province::findByNameOrAlias($folderName);

            if (! $province) {
                $this->warn("Bỏ qua thư mục \"{$folderName}\": không khớp tỉnh/thành nào trong hệ thống.");
                $exitCode = self::FAILURE;

                continue;
            }

            $this->importFolder("{$path}/{$folderName}", $province);
            $this->newLine();
        }

        return $exitCode;
    }

    private function importFolder(string $path, Province $province): int
    {
        $files = collect(scandir($path))
            ->reject(fn ($f) => in_array($f, ['.', '..', '.DS_Store']))
            ->reject(fn ($f) => str_starts_with($f, '._'))
            ->filter(fn ($f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['docx', 'pdf']))
            ->values();

        if ($files->isEmpty()) {
            $this->warn("[{$province->name}] Không có file .docx/.pdf nào trong thư mục này.");

            return self::SUCCESS;
        }

        $this->info("Tỉnh: {$province->name} — {$files->count()} file. Đang upload lên R2 và đưa vào hàng đợi...");

        $bar = $this->output->createProgressBar($files->count());
        $bar->start();

        $created = 0;
        $skipped = 0;

        foreach ($files as $filename) {
            $fullPath = "{$path}/{$filename}";
            $fileType = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'pdf' ? 'pdf' : 'docx';
            $r2Path = "tang-ni/{$province->slug}/{$filename}";

            if (MonasticDocument::where('file_path', $r2Path)->where('status', 'ready')->exists()) {
                $skipped++;
                $bar->advance();

                continue;
            }

            Storage::disk('public')->put($r2Path, file_get_contents($fullPath));

            $document = MonasticDocument::create([
                'province_id' => $province->id,
                'file_path'   => $r2Path,
                'file_name'   => $filename,
                'file_type'   => $fileType,
                'file_size'   => filesize($fullPath) ?: 0,
                'status'      => 'pending',
            ]);

            ProcessMonasticDocumentJob::dispatch($document);
            $created++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("[{$province->name}] Đã đưa {$created} file vào hàng đợi xử lý".($skipped ? ", bỏ qua {$skipped} file đã xử lý xong trước đó" : '').'.');

        return self::SUCCESS;
    }
}
