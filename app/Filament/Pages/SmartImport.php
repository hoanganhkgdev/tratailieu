<?php

namespace App\Filament\Pages;

use App\Jobs\ProcessDocumentJob;
use App\Models\Province;
use App\Services\SmartImportService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;

class SmartImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-sparkles';
    protected static ?string $navigationLabel = 'Import';
    protected static ?string $title           = 'Smart Import — AI tự nhận diện';
    protected static ?string $navigationGroup = 'Tự viện';
    protected static ?int    $navigationSort  = 2;
    protected static string  $view            = 'filament.pages.smart-import';

    public array  $files      = [];
    public array  $previews   = [];
    public bool   $analyzing  = false;
    public bool   $confirmed  = false;

    /**
     * Danh sách 34 tỉnh chính thức để người dùng tự chọn khi AI không khớp được
     * tên tỉnh trong tài liệu (vd: tài liệu ghi địa danh trước sáp nhập).
     */
    public function getProvincesProperty()
    {
        return Province::query()->orderBy('region')->orderBy('name')->get(['id', 'name', 'region']);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Upload tài liệu')
                ->description('Hỗ trợ PDF và DOCX. Có thể upload nhiều file cùng lúc.')
                ->schema([
                    Forms\Components\FileUpload::make('files')
                        ->label('Chọn file')
                        ->multiple()
                        ->acceptedFileTypes([
                            'application/pdf',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        ])
                        ->maxSize(50 * 1024)
                        ->maxFiles(10)
                        ->directory('imports/temp')
                        ->preserveFilenames()
                        ->required(),
                ]),
        ]);
    }

    public function analyze(): void
    {
        // getState() xử lý FileUpload, move file từ livewire-tmp sang imports/temp
        $state     = $this->form->getState();
        $filePaths = $state['files'] ?? [];

        if (empty($filePaths)) {
            Notification::make()->title('Vui lòng chọn ít nhất 1 file')->warning()->send();
            return;
        }

        $this->previews  = [];
        $this->analyzing = true;

        $service = app(SmartImportService::class);

        foreach ($filePaths as $filePath) {
            // File đã được move sang imports/temp bởi getState(), nằm trên public disk
            $absolutePath = \Illuminate\Support\Facades\Storage::disk('public')->path($filePath);

            if (!file_exists($absolutePath)) {
                $this->previews[] = [
                    '_file_path'     => $filePath,
                    '_file_name'     => basename($filePath),
                    '_file_type'     => 'docx',
                    '_error'         => "Không tìm thấy file sau khi xử lý: {$filePath}",
                    'document_title' => basename($filePath),
                    'temple_name'    => null,
                    'province_name'  => null,
                ];
                continue;
            }

            // Detect file type từ extension hoặc MIME
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'docx'])) {
                $mime = mime_content_type($absolutePath) ?: '';
                $ext  = str_contains($mime, 'pdf') ? 'pdf' : 'docx';
            }

            $fileName = basename($filePath);
            $fileType = $ext;

            try {
                $data = $service->analyze($filePath, $fileType);
                $this->previews[] = array_merge($data, [
                    '_file_path' => $filePath,
                    '_file_name' => $fileName,
                    '_file_type' => $fileType,
                    '_error'     => null,
                ]);
            } catch (\Throwable $e) {
                $this->previews[] = [
                    '_file_path'          => $filePath,
                    '_file_name'          => $fileName,
                    '_file_type'          => $fileType,
                    '_error'              => $e->getMessage(),
                    'document_title'      => $fileName,
                    'temple_name'         => null,
                    'province_name'       => null,
                ];
            }
        }

        $this->analyzing = false;
    }

    public function import(): void
    {
        if (empty($this->previews)) return;

        $service  = app(SmartImportService::class);
        $success  = 0;
        $skipped  = 0;
        $remaining = [];

        foreach ($this->previews as $preview) {
            if (!empty($preview['_error'])) {
                $remaining[] = $preview;
                continue;
            }

            if (empty($preview['province_id'])) {
                $skipped++;
                $remaining[] = $preview;
                continue;
            }

            try {
                $newPath = 'documents/' . basename($preview['_file_path']);
                Storage::move($preview['_file_path'], $newPath);

                $document = $service->import(
                    $newPath,
                    $preview['_file_name'],
                    $preview['_file_type'],
                    $preview,
                );

                ProcessDocumentJob::dispatch($document);
                $success++;
            } catch (\Throwable $e) {
                Notification::make()
                    ->title('Lỗi: ' . $preview['_file_name'])
                    ->body($e->getMessage())
                    ->danger()->send();
                $remaining[] = $preview;
            }
        }

        $this->previews = $remaining;

        if ($skipped > 0) {
            Notification::make()
                ->title("Còn {$skipped} tài liệu chưa chọn tỉnh/thành")
                ->body('Vui lòng chọn tỉnh ở mục "Tỉnh/Thành phố" cho các tài liệu còn lại rồi nhấn Import lần nữa.')
                ->warning()->send();
        }

        if ($success > 0) {
            if (empty($this->previews)) {
                $this->files = [];
            }

            Notification::make()
                ->title("Đã import {$success} tài liệu thành công!")
                ->body('AI đang xử lý tài liệu ở nền, vui lòng chạy queue worker.')
                ->success()->send();
        }
    }

    public function cancelPreview(): void
    {
        foreach ($this->previews as $preview) {
            Storage::delete($preview['_file_path'] ?? '');
        }
        $this->previews = [];
        $this->files    = [];
    }
}
