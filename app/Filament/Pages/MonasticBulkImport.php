<?php

namespace App\Filament\Pages;

use App\Jobs\AnalyzeAndImportMonasticJob;
use App\Models\Province;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;
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

        $disk = Storage::disk('public');
        $storedPath = $this->zipFile->store('imports/bulk', 'public');
        $absoluteZipPath = $disk->path($storedPath);

        $zip = new ZipArchive();
        if ($zip->open($absoluteZipPath) !== true) {
            Notification::make()->title('Không thể mở file ZIP')->danger()->send();
            return;
        }

        $extractDir = 'imports/monastic_' . Str::slug($province->name) . '_' . uniqid();
        $absoluteExtractDir = $disk->path($extractDir);
        @mkdir($absoluteExtractDir, 0755, true);

        $zip->extractTo($absoluteExtractDir);
        $zip->close();
        $disk->delete($storedPath);

        $files = collect($disk->allFiles($extractDir))
            ->filter(function ($f) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                $name = basename($f);
                return in_array($ext, ['pdf', 'docx']) && ! str_starts_with($name, '.');
            })
            ->values();

        if ($files->isEmpty()) {
            Notification::make()->title('Không tìm thấy file PDF/DOCX trong ZIP')->warning()->send();
            return;
        }

        $this->batchId = (string) Str::uuid();
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

    public function resetForm(): void
    {
        $this->zipFile = null;
        $this->provinceId = '';
        $this->fileCount = 0;
        $this->imported = false;
    }
}
