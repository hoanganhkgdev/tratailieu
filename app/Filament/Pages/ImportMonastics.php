<?php

namespace App\Filament\Pages;

use App\Filament\Resources\MonasticDocumentResource;
use App\Jobs\ProcessMonasticDocumentJob;
use App\Models\MonasticDocument;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ImportMonastics extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Nhập hồ sơ tăng ni';

    protected static ?string $navigationGroup = 'Tăng ni';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Nhập hồ sơ tăng ni';

    protected static string $view = 'filament.pages.import-monastics';

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
                    ->label('Tỉnh/Thành')
                    ->helperText('Chỉ định tỉnh cho cả đợt upload này — hệ thống không còn tự đoán tỉnh từ nội dung phiếu (dễ nhầm địa danh con), phải chọn trước.')
                    // Trang này KHÔNG gắn với 1 Eloquent record cụ thể (không phải
                    // Resource form) nên ->relationship() không dùng được — phải nạp
                    // options() trực tiếp.
                    ->options(fn () => \App\Models\Province::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                FileUpload::make('files')
                    ->label('Phiếu hồ sơ tăng ni (PDF/DOCX)')
                    ->helperText('Mỗi file là 1 phiếu cá nhân (Phiếu số 3). Có thể chọn nhiều file cùng lúc.')
                    ->multiple()
                    ->preserveFilenames()
                    ->disk('public')
                    ->directory('tang-ni')
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
        $provinceId = $state['province_id'];

        foreach ($paths as $path) {
            $fileType = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf' ? 'pdf' : 'docx';

            $document = MonasticDocument::create([
                'uploaded_by' => Auth::id(),
                'province_id' => $provinceId,
                'file_path'   => $path,
                'file_name'   => basename($path),
                'file_type'   => $fileType,
                'file_size'   => Storage::disk('public')->size($path) ?: 0,
                'status'      => 'pending',
            ]);

            ProcessMonasticDocumentJob::dispatch($document);
        }

        Notification::make()
            ->title('Đã tải lên '.count($paths).' file, đang xử lý AI...')
            ->success()
            ->send();

        $this->form->fill();

        $this->redirect(MonasticDocumentResource::getUrl());
    }
}
