<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentResource\Pages;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Tài liệu';
    protected static ?string $modelLabel = 'Tài liệu';
    protected static ?string $pluralModelLabel = 'Quản lý tài liệu';
    protected static ?string $navigationGroup = 'Tự viện';
    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('monastic_id');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin tài liệu')->schema([
                Forms\Components\Select::make('temple_id')
                    ->label('Chùa / Tự viện')
                    ->relationship('temple', 'name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->required(fn (Forms\Get $get) => blank($get('monastic_id')))
                    ->helperText('Bắt buộc nếu không chọn Tăng Ni bên dưới'),
                Forms\Components\Select::make('monastic_id')
                    ->label('Tăng Ni')
                    ->relationship('monastic', 'full_name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->required(fn (Forms\Get $get) => blank($get('temple_id')))
                    ->helperText('Chọn nếu tài liệu thuộc về hồ sơ 1 vị Tăng/Ni cụ thể (vd: quyết định bổ nhiệm, văn bằng...)'),
                Forms\Components\TextInput::make('title')
                    ->label('Tiêu đề')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->label('Mô tả')
                    ->rows(3)
                    ->columnSpanFull(),
            ])->columns(2),

            Forms\Components\Section::make('Upload file')->schema([
                Forms\Components\FileUpload::make('file_path')
                    ->label('File tài liệu (PDF / DOCX)')
                    ->required()
                    ->acceptedFileTypes(['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                    ->maxSize(50 * 1024)
                    ->directory('documents')
                    ->preserveFilenames()
                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                        if ($state) {
                            $ext = strtolower(pathinfo($state, PATHINFO_EXTENSION));
                            $set('file_type', $ext === 'pdf' ? 'pdf' : 'docx');
                            $set('file_name', basename($state));
                        }
                    })
                    ->columnSpanFull(),
                Forms\Components\Hidden::make('file_type'),
                Forms\Components\Hidden::make('file_name'),
                Forms\Components\Hidden::make('file_size')->default(0),
                Forms\Components\Hidden::make('uploaded_by')->default(fn () => Auth::id()),
                Forms\Components\Hidden::make('status')->default('pending'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('row_number')->label('STT')->rowIndex()->alignCenter(),
                Tables\Columns\TextColumn::make('temple.name')->label('Chùa')->searchable()->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('province_name')->label('Tỉnh')->sortable(false)->toggleable()
                    ->getStateUsing(fn (Document $record) => $record->temple?->province?->name
                        ?? $record->monastic?->province?->name
                        ?? $record->monastic?->temple?->province?->name),
                Tables\Columns\BadgeColumn::make('file_type')->label('Loại')
                    ->colors(['danger' => 'pdf', 'info' => 'docx']),
                Tables\Columns\BadgeColumn::make('status')->label('Trạng thái')
                    ->colors([
                        'gray'    => 'pending',
                        'warning' => 'processing',
                        'success' => 'ready',
                        'danger'  => 'failed',
                    ])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending'    => 'Chờ xử lý',
                        'processing' => 'Đang xử lý',
                        'ready'      => 'Sẵn sàng',
                        'failed'     => 'Lỗi',
                        default      => $state,
                    }),
                Tables\Columns\TextColumn::make('created_at')->label('Ngày upload')
                    ->dateTime('d/m/Y H:i')->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('temple_id')->label('Chùa')
                    ->relationship('temple', 'name')->searchable()->preload(),
                Tables\Filters\SelectFilter::make('monastic_id')->label('Tăng Ni')
                    ->relationship('monastic', 'full_name')->searchable()->preload(),
                Tables\Filters\SelectFilter::make('status')->label('Trạng thái')
                    ->options(['pending' => 'Chờ xử lý', 'processing' => 'Đang xử lý', 'ready' => 'Sẵn sàng', 'failed' => 'Lỗi']),
                Tables\Filters\SelectFilter::make('file_type')->label('Loại file')
                    ->options(['pdf' => 'PDF', 'docx' => 'DOCX']),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->label('Tải xuống')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Document $record) => Storage::url($record->file_path))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('reprocess')
                    ->label('Xử lý lại')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (Document $record) => in_array($record->status, ['failed', 'pending']))
                    ->action(fn (Document $record) => ProcessDocumentJob::dispatch($record)),
                Tables\Actions\EditAction::make()->label('Sửa'),
                Tables\Actions\DeleteAction::make()->label('Xóa')
                    ->after(fn (Document $record) => Storage::delete($record->file_path)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Xóa đã chọn'),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDocuments::route('/'),
            'create' => Pages\CreateDocument::route('/create'),
            'edit'   => Pages\EditDocument::route('/{record}/edit'),
        ];
    }
}
