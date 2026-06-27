<?php

namespace App\Filament\Pages;

use App\Jobs\ImportTempleFileJob;
use App\Models\Province;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;
use ZipArchive;

class BulkImport extends Page
{
    use WithFileUploads;

    protected static ?string $navigationIcon  = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationLabel = 'Import hàng loạt';
    protected static ?string $title           = 'Import hàng loạt — Upload ZIP';
    protected static ?string $navigationGroup = 'Tự viện';
    protected static ?int    $navigationSort  = 4;
    protected static string  $view            = 'filament.pages.bulk-import';

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

        $extractDir = 'imports/' . \Illuminate\Support\Str::slug($province->name) . '_' . uniqid();
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

        foreach ($files as $file) {
            ImportTempleFileJob::dispatch($file, $province->id);
        }

        $this->fileCount = $files->count();
        $this->imported = true;

        Notification::make()
            ->title("Đã đẩy {$this->fileCount} file vào hàng đợi xử lý!")
            ->body("Tỉnh: {$province->name}. Hệ thống sẽ tự động xử lý nền.")
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
