<?php

namespace App\Filament\Pages;

use App\Filament\Resources\DocumentResource;
use App\Jobs\ProcessTempleDocumentJob;
use App\Models\Document;
use App\Models\Province;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ImportTemples extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Nhập hồ sơ tự viện';

    protected static ?string $title = 'Nhập hồ sơ tự viện';

    protected static string $view = 'filament.pages.import-temples';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('province_id')
                    ->label('Tỉnh/Thành của các hồ sơ này')
                    ->helperText('Toàn bộ file chọn bên dưới sẽ được ghi nhận vào tỉnh này — AI sẽ không tự đoán tỉnh nữa, tránh nhầm lẫn.')
                    ->options(Province::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                FileUpload::make('files')
                    ->label('File hồ sơ tự viện (PDF/DOCX)')
                    ->helperText('Có thể chọn nhiều file cùng lúc. Hệ thống sẽ tự động đọc và trích xuất thông tin.')
                    ->multiple()
                    ->preserveFilenames()
                    ->disk('public')
                    ->directory('temples')
                    ->acceptedFileTypes([
                        'application/pdf',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ])
                    ->required(),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $state = $this->form->getState();
        $paths = $state['files'] ?? [];
        $provinceId = $state['province_id'] ?? null;

        foreach ($paths as $path) {
            $fileType = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf' ? 'pdf' : 'docx';

            $document = Document::create([
                'uploaded_by' => Auth::id(),
                'province_id' => $provinceId,
                'file_path'   => $path,
                'file_name'   => basename($path),
                'file_type'   => $fileType,
                'file_size'   => Storage::disk('public')->size($path) ?: 0,
                'status'      => 'pending',
            ]);

            ProcessTempleDocumentJob::dispatch($document);
        }

        Notification::make()
            ->title('Đã tải lên '.count($paths).' file, đang xử lý AI...')
            ->success()
            ->send();

        $this->form->fill();

        $this->redirect(DocumentResource::getUrl());
    }
}
