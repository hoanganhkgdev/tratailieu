<?php

namespace App\Filament\Pages;

use App\Jobs\ImportTempleFileJob;
use App\Models\Province;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class BulkImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationLabel = 'Import hàng loạt';
    protected static ?string $title           = 'Import hàng loạt — Upload ZIP';
    protected static ?string $navigationGroup = 'Tự viện';
    protected static ?int    $navigationSort  = 4;
    protected static string  $view            = 'filament.pages.bulk-import';

    public ?string $zipFile    = null;
    public ?string $provinceId = null;
    public int     $fileCount  = 0;
    public bool    $imported   = false;

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Upload thư mục tài liệu')
                ->description('Nén thư mục chứa file PDF/DOCX thành file ZIP rồi upload lên. Hệ thống sẽ tự giải nén và import.')
                ->schema([
                    Forms\Components\Select::make('provinceId')
                        ->label('Tỉnh/Thành phố')
                        ->options(Province::orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                    Forms\Components\FileUpload::make('zipFile')
                        ->label('File ZIP')
                        ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/octet-stream', 'multipart/x-zip'])
                        ->maxSize(1024 * 1024)
                        ->directory('imports/bulk')
                        ->required(),
                ]),
        ]);
    }

    public function import(): void
    {
        $state = $this->form->getState();

        $zipPath = $state['zipFile'] ?? null;
        $provinceId = $state['provinceId'] ?? null;

        if (! $zipPath || ! $provinceId) {
            Notification::make()->title('Vui lòng chọn tỉnh và upload file ZIP')->warning()->send();
            return;
        }

        $province = Province::find($provinceId);
        if (! $province) {
            Notification::make()->title('Tỉnh không hợp lệ')->danger()->send();
            return;
        }

        $disk = Storage::disk('public');
        $absoluteZipPath = $disk->path($zipPath);

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

        $disk->delete($zipPath);

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
        $this->provinceId = null;
        $this->fileCount = 0;
        $this->imported = false;
        $this->form->fill();
    }
}
