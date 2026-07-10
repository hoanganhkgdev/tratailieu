<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentResource\Pages;
use App\Jobs\ProcessTempleDocumentJob;
use App\Models\Document;
use App\Models\Province;
use App\Services\TempleImportService;
use Filament\Forms;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Tiến trình xử lý';

    protected static ?string $navigationGroup = 'Tự viện';

    protected static ?string $modelLabel = 'tài liệu';

    protected static ?string $pluralModelLabel = 'Tài liệu';

    protected static ?int $navigationSort = 1;

    public static array $statusLabels = [
        'pending'    => 'Chờ xử lý',
        'processing' => 'Đang xử lý',
        'ready'      => 'Hoàn tất',
        'failed'     => 'Lỗi',
    ];

    public static array $statusColors = [
        'pending'    => 'gray',
        'processing' => 'warning',
        'ready'      => 'success',
        'failed'     => 'danger',
    ];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Thông tin file')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('file_name')->label('Tên file'),
                        TextEntry::make('file_type')->label('Định dạng'),
                        TextEntry::make('temple.name')->label('Tự viện')->placeholder('Chưa xác định'),
                        TextEntry::make('status')
                            ->label('Trạng thái')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => self::$statusLabels[$state] ?? $state)
                            ->color(fn (string $state): string => self::$statusColors[$state] ?? 'gray'),
                        TextEntry::make('created_at')->label('Thời gian tải lên')->dateTime('d/m/Y H:i'),
                        TextEntry::make('processed_at')->label('Thời gian xử lý xong')->dateTime('d/m/Y H:i')->placeholder('—'),
                    ]),
                Section::make('Lỗi xử lý')
                    ->visible(fn (Document $record): bool => filled($record->error_message))
                    ->schema([
                        TextEntry::make('error_message')->label('')->color('danger'),
                    ]),
                Section::make('Kết quả AI trích xuất')
                    ->visible(fn (Document $record): bool => filled($record->extracted_json))
                    ->schema([
                        TextEntry::make('extracted_json')
                            ->label('')
                            // ->state() trả thẳng state đã format, tránh việc Filament coi
                            // mảng thô của entry là "danh sách nhiều giá trị" rồi implode
                            // trước khi formatStateUsing kịp chạy (vỡ vì extracted_json là
                            // object lồng nhau, có key 'monastics' chứa mảng con).
                            ->state(fn (Document $record): string => $record->extracted_json
                                ? json_encode($record->extracted_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                                : ''
                            )
                            ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre-wrap'])
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('3s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('file_name')
                    ->label('File')
                    ->searchable()
                    ->icon(fn (Document $record): string => $record->file_type === 'pdf' ? 'heroicon-o-document-text' : 'heroicon-o-document'),
                Tables\Columns\TextColumn::make('temple.name')
                    ->label('Tự viện')
                    ->placeholder('Chưa xác định')
                    ->searchable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Trạng thái')
                    ->formatStateUsing(fn (string $state): string => self::$statusLabels[$state] ?? $state)
                    ->colors([
                        'gray'    => 'pending',
                        'warning' => 'processing',
                        'success' => 'ready',
                        'danger'  => 'failed',
                    ]),
                Tables\Columns\TextColumn::make('error_message')
                    ->label('Lỗi')
                    ->limit(40)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—')
                    ->color('danger'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tải lên lúc')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('processed_at')
                    ->label('Xong lúc')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(self::$statusLabels),
            ])
            ->actions([
                Tables\Actions\Action::make('retry')
                    ->label(fn (Document $record): string => $record->status === 'processing' ? 'Xử lý lại (đang treo)' : 'Xử lý lại')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Document $record): bool => $record->status === 'failed'
                        || ($record->status === 'processing' && $record->updated_at->lt(now()->subMinutes(5))))
                    ->action(function (Document $record) {
                        $record->update([
                            'status'         => 'pending',
                            'error_message'  => null,
                            'processed_at'   => null,
                            'temple_id'      => null,
                        ]);

                        ProcessTempleDocumentJob::dispatch($record);

                        Notification::make()
                            ->title('Đã đưa vào hàng đợi để xử lý lại')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('assignProvince')
                    ->label('Gán tỉnh thủ công')
                    ->icon('heroicon-o-map-pin')
                    ->color('gray')
                    ->visible(fn (Document $record): bool => $record->status === 'failed' && blank($record->temple_id) && filled($record->extracted_json))
                    ->form([
                        Forms\Components\Select::make('province_id')
                            ->label('Tỉnh/Thành')
                            ->options(Province::query()->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Document $record, array $data, TempleImportService $importer) {
                        $importer->assignProvince($record, Province::findOrFail($data['province_id']));

                        Notification::make()
                            ->title('Đã gán tỉnh và ghi nhận tự viện')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('retry')
                        ->label('Xử lý lại các mục lỗi đã chọn')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $count = 0;

                            foreach ($records as $record) {
                                $isStuckProcessing = $record->status === 'processing'
                                    && $record->updated_at->lt(now()->subMinutes(5));

                                if ($record->status !== 'failed' && ! $isStuckProcessing) {
                                    continue;
                                }

                                $record->update([
                                    'status'        => 'pending',
                                    'error_message' => null,
                                    'processed_at'  => null,
                                    'temple_id'     => null,
                                ]);

                                ProcessTempleDocumentJob::dispatch($record);
                                $count++;
                            }

                            Notification::make()
                                ->title("Đã đưa {$count} tài liệu vào hàng đợi xử lý lại")
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\BulkAction::make('deleteNonReady')
                        ->label('Xoá các mục đã chọn')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Tài liệu đã "Hoàn tất" sẽ KHÔNG bị xoá — xoá sẽ làm mất link tải file gốc của tự viện liên quan dù bản thân tự viện vẫn còn. Chỉ các mục lỗi/chờ xử lý mới bị xoá.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $deletable = $records->reject(fn (Document $record) => $record->status === 'ready');
                            $skipped = $records->count() - $deletable->count();

                            $deletable->each->delete();

                            Notification::make()
                                ->title("Đã xoá {$deletable->count()} tài liệu".($skipped ? ", bỏ qua {$skipped} tài liệu đã hoàn tất" : ''))
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocuments::route('/'),
            'view'  => Pages\ViewDocument::route('/{record}'),
        ];
    }
}
