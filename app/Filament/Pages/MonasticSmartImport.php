<?php

namespace App\Filament\Pages;

use App\Jobs\AnalyzeMonasticFileJob;
use App\Models\Province;
use App\Models\Temple;
use App\Services\MonasticImportService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MonasticSmartImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-sparkles';
    protected static ?string $navigationLabel = 'Import';
    protected static ?string $title           = 'Nhập Tăng Ni bằng AI — từ Phiếu số 3';
    protected static ?string $navigationGroup = 'Tăng Ni';
    protected static ?int    $navigationSort  = 2;
    protected static string  $view            = 'filament.pages.monastic-smart-import';

    public array  $files      = [];
    public array  $previews   = [];
    public bool   $analyzing  = false;
    public string $batchId    = '';
    public int    $totalFiles = 0;
    public int    $doneFiles  = 0;

    /**
     * Danh sách chùa hiện có để người dùng tự chọn khi AI không khớp được tên chùa
     * trong phiếu (vd: phiếu ghi tên viết tắt, tên cũ, hoặc chùa chưa có trong hệ thống).
     */
    public function getTemplesProperty()
    {
        return Temple::query()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Danh sách 34 tỉnh chính thức để người dùng tự chọn khi AI không khớp được
     * tỉnh/thành trong phiếu (vd: phiếu ghi địa danh trước sáp nhập).
     */
    public function getProvincesProperty()
    {
        return Province::query()->orderBy('region')->orderBy('name')->get(['id', 'name', 'region']);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Upload phiếu thông tin Tăng Ni')
                ->description('Hỗ trợ PDF và DOCX — mỗi file là phiếu thông tin của 1 người (Phiếu số 3 đã điền). Có thể upload nhiều file cùng lúc.')
                ->schema([
                    Forms\Components\FileUpload::make('files')
                        ->label('Chọn file')
                        ->multiple()
                        ->acceptedFileTypes([
                            'application/pdf',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        ])
                        ->maxSize(50 * 1024)
                        ->maxFiles(20)
                        ->directory('imports/temp')
                        ->preserveFilenames()
                        ->required(),
                ]),
        ]);
    }

    public function analyze(): void
    {
        $state     = $this->form->getState();
        $filePaths = $state['files'] ?? [];

        if (empty($filePaths)) {
            Notification::make()->title('Vui lòng chọn ít nhất 1 file')->warning()->send();
            return;
        }

        $this->previews   = [];
        $this->batchId    = (string) Str::uuid();
        $this->totalFiles = count($filePaths);
        $this->doneFiles  = 0;
        $this->analyzing  = true;

        Cache::put("monastic_analysis_{$this->batchId}_done", 0, now()->addHours(2));

        foreach ($filePaths as $index => $filePath) {
            $absolutePath = Storage::disk('public')->path($filePath);
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (! in_array($ext, ['pdf', 'docx'])) {
                $mime = file_exists($absolutePath) ? (mime_content_type($absolutePath) ?: '') : '';
                $ext  = str_contains($mime, 'pdf') ? 'pdf' : 'docx';
            }

            AnalyzeMonasticFileJob::dispatch($this->batchId, $index, $filePath, $ext);
        }
    }

    public function checkAnalysis(): void
    {
        if (! $this->analyzing || empty($this->batchId)) {
            return;
        }

        $done            = (int) Cache::get("monastic_analysis_{$this->batchId}_done", 0);
        $this->doneFiles = $done;

        if ($done < $this->totalFiles) {
            return;
        }

        $previews = [];
        for ($i = 0; $i < $this->totalFiles; $i++) {
            $result = Cache::get("monastic_analysis_{$this->batchId}_{$i}");
            if ($result !== null) {
                $previews[] = $result;
            }
        }

        $this->previews  = $previews;
        $this->analyzing = false;
        $this->batchId   = '';
    }

    public function import(): void
    {
        if (empty($this->previews)) {
            return;
        }

        $service   = app(MonasticImportService::class);
        $success   = 0;
        $remaining = [];

        foreach ($this->previews as $preview) {
            if (! empty($preview['_error'])) {
                $remaining[] = $preview;
                continue;
            }

            try {
                // Lưu ý: service sẽ tự di chuyển file gốc từ thư mục tạm sang nơi lưu trữ
                // tài liệu vĩnh viễn và gắn vào hồ sơ — không xoá file ở đây nữa.
                $service->import($preview);
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

        if ($success > 0) {
            if (empty($this->previews)) {
                $this->files = [];
            }

            Notification::make()
                ->title("Đã tạo {$success} hồ sơ Tăng Ni thành công!")
                ->body('Vui lòng vào danh sách Tăng Ni để kiểm tra và bổ sung thêm thông tin chi tiết nếu cần.')
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

    private function errorPreview(string $filePath, string $fileType, string $error): array
    {
        return [
            '_file_path'      => $filePath,
            '_file_name'      => basename($filePath),
            '_file_type'      => $fileType,
            '_error'          => $error,
            'full_name'       => null,
            'religious_name'  => null,
            'gender'          => 'nam',
            'temple_name'     => null,
            'temple_id'       => null,
            'province_name'   => null,
            'province_id'     => null,
            'rank'            => null,
            'current_position'=> null,
            'phone'           => null,
        ];
    }
}
