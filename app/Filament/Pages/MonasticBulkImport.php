<?php

namespace App\Filament\Pages;

use App\Jobs\AnalyzeAndImportMonasticJob;
use App\Models\Province;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;
use Symfony\Component\Finder\Finder;
use ZipArchive;

class MonasticBulkImport extends Page
{
    use WithFileUploads;

    protected static ?string $navigationIcon  = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationLabel = 'Import hàng loạt';
    protected static ?string $title           = 'Import hàng loạt Tăng Ni — Upload ZIP';
    protected static ?string $navigationGroup = 'Tăng Ni';
    protected static ?int    $navigationSort  = 3;
    protected static string  $view            = 'filament.pages.monastic-bulk-import';

    public $zipFile;
    public $provinceId = '';
    public int  $fileCount = 0;
    public bool $imported  = false;

    public function getProvincesProperty()
    {
        return Province::orderBy('name')->get(['id', 'name']);
    }

    public function import(): void
    {
        if (! $this->zipFile || ! $this->provinceId) {
            Notification::make()->title('Vui lòng chọn tỉnh và upload file ZIP')->warning()->send();
            return;
        }

        $province = Province::find($this->provinceId);
        if (! $province) {
            Notification::make()->title('Tỉnh không hợp lệ')->danger()->send();
            return;
        }

        // ZipArchive cần 1 path local thật — file upload của Livewire luôn nằm tạm
        // trên đĩa local trước khi được lưu vào disk chính (S3/R2), nên dùng thẳng path đó.
        $localZipPath = $this->zipFile->getRealPath();

        $zip = new ZipArchive();
        if ($zip->open($localZipPath) !== true) {
            Notification::make()->title('Không thể mở file ZIP')->danger()->send();
            return;
        }

        $localExtractDir = sys_get_temp_dir() . '/monastic_bulk_import_' . uniqid();
        @mkdir($localExtractDir, 0755, true);
        $zip->extractTo($localExtractDir);
        $zip->close();

        $remoteDir = 'imports/monastic_' . Str::slug($province->name) . '_' . uniqid();
        $disk = Storage::disk('public');
        $files = collect();

        foreach (Finder::create()->files()->in($localExtractDir) as $localFile) {
            $ext  = strtolower($localFile->getExtension());
            $name = $localFile->getFilename();

            if (! in_array($ext, ['pdf', 'docx']) || str_starts_with($name, '.')) {
                continue;
            }

            $remotePath = $remoteDir . '/' . $name;
            $disk->put($remotePath, file_get_contents($localFile->getRealPath()));
            $files->push($remotePath);
        }

        $this->deleteDirectory($localExtractDir);

        if ($files->isEmpty()) {
            Notification::make()->title('Không tìm thấy file PDF/DOCX trong ZIP')->warning()->send();
            return;
        }

        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            AnalyzeAndImportMonasticJob::dispatch($file, $province->id, $ext);
        }

        $this->fileCount = $files->count();
        $this->imported = true;

        Notification::make()
            ->title("Đã đẩy {$this->fileCount} phiếu Tăng Ni vào hàng đợi!")
            ->body("Tỉnh: {$province->name}. AI sẽ phân tích và tạo hồ sơ tự động.")
            ->success()
            ->send();
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (Finder::create()->in($dir)->depth('== 0') as $item) {
            $item->isDir() ? $this->deleteDirectory($item->getRealPath()) : @unlink($item->getRealPath());
        }

        @rmdir($dir);
    }

    public function resetForm(): void
    {
        $this->zipFile = null;
        $this->provinceId = '';
        $this->fileCount = 0;
        $this->imported = false;
    }
}
