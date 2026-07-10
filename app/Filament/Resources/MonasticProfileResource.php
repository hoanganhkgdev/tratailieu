<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MonasticProfileResource\Pages;
use App\Models\MonasticProfile;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MonasticProfileResource extends Resource
{
    protected static ?string $model = MonasticProfile::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Tăng ni';

    protected static ?string $modelLabel = 'tăng ni';

    protected static ?string $pluralModelLabel = 'Tăng ni';

    public static array $classificationLabels = [
        'chuc_sac'    => 'Chức sắc',
        'chuc_viec'   => 'Chức việc',
        'nha_tu_hanh' => 'Nhà tu hành',
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('I. Định danh & cá nhân cơ bản')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('full_name')
                            ->label('Họ và tên khai sinh')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('religious_name')
                            ->label('Tên trong tôn giáo / Pháp danh')
                            ->maxLength(255),
                        Forms\Components\DatePicker::make('birth_date')
                            ->label('Ngày sinh'),
                        Forms\Components\Select::make('gender')
                            ->label('Giới tính')
                            ->options(['Nam' => 'Nam', 'Nữ' => 'Nữ']),
                        Forms\Components\TextInput::make('ethnicity')
                            ->label('Dân tộc')
                            ->maxLength(100),
                        Forms\Components\TextInput::make('nationality')
                            ->label('Quốc tịch')
                            ->maxLength(100),
                        Forms\Components\TextInput::make('id_number')
                            ->label('Số CCCD')
                            ->maxLength(30),
                        Forms\Components\DatePicker::make('id_issued_date')
                            ->label('Ngày cấp CCCD'),
                        Forms\Components\TextInput::make('id_issued_place')
                            ->label('Nơi cấp CCCD')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('hometown')
                            ->label('Quê quán')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('permanent_address')
                            ->label('Địa chỉ thường trú')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('current_address')
                            ->label('Nơi ở hiện tại')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\Select::make('province_id')
                            ->label('Tỉnh/Thành')
                            ->relationship('province', 'name')
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('temple_id')
                            ->label('Tự viện')
                            ->relationship('temple', 'name')
                            ->searchable()
                            ->preload(),
                        Forms\Components\TextInput::make('monastic_cert_number')
                            ->label('Số chứng nhận Tăng ni')
                            ->maxLength(100),
                        Forms\Components\DatePicker::make('monastic_cert_date')
                            ->label('Ngày cấp chứng nhận'),
                    ]),
                Forms\Components\Section::make('II. Hành đạo & chuyên môn tôn giáo')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('religion')
                            ->label('Tôn giáo')
                            ->maxLength(100),
                        Forms\Components\TextInput::make('religious_org')
                            ->label('Tổ chức tôn giáo')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('sect')
                            ->label('Hệ phái/Dòng tu')
                            ->maxLength(255),
                        Forms\Components\CheckboxList::make('classification')
                            ->label('Phân loại')
                            ->options(self::$classificationLabels),
                        Forms\Components\Textarea::make('current_position')
                            ->label('Chức vụ/Phẩm vị hiện tại')
                            ->columnSpanFull(),
                        Forms\Components\DatePicker::make('ordination_date')
                            ->label('Ngày thụ phong/bổ nhiệm'),
                        Forms\Components\TextInput::make('activity_scope')
                            ->label('Phạm vi hoạt động')
                            ->maxLength(255),
                        Forms\Components\Textarea::make('concurrent_position')
                            ->label('Chức vụ kiêm nhiệm')
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('notes')
                            ->label('Ghi chú')
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('III. Đào tạo')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('education_level')
                            ->label('Trình độ học vấn phổ thông')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('professional_qualification')
                            ->label('Trình độ chuyên môn')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('religious_education_level')
                            ->label('Trình độ tu học')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('languages')
                            ->label('Ngoại ngữ/Tiếng dân tộc')
                            ->maxLength(255),
                        Forms\Components\Textarea::make('training_institutions')
                            ->label('Cơ sở đào tạo tôn giáo')
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('IV. Hoạt động & bổ nhiệm')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Textarea::make('activity_history')
                            ->label('Quá trình hoạt động')
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('commendation_discipline')
                            ->label('Khen thưởng/Kỷ luật'),
                        Forms\Components\Textarea::make('violations')
                            ->label('Khiếu kiện, vi phạm'),
                        Forms\Components\TextInput::make('congress_term')
                            ->label('Nhiệm kỳ đại hội')
                            ->maxLength(255),
                    ]),
                Forms\Components\Section::make('V. Liên hệ & tình trạng')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('phone')
                            ->label('Số điện thoại')
                            ->tel()
                            ->maxLength(30),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('status')
                            ->label('Tình trạng hiện tại')
                            ->maxLength(255),
                    ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('I. Định danh & cá nhân cơ bản')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('full_name')->label('Họ và tên khai sinh'),
                        TextEntry::make('religious_name')->label('Tên trong tôn giáo / Pháp danh')->placeholder('—'),
                        TextEntry::make('birth_date')->label('Ngày sinh')->date('d/m/Y')->placeholder('—'),
                        TextEntry::make('gender')->label('Giới tính')->placeholder('—'),
                        TextEntry::make('ethnicity')->label('Dân tộc')->placeholder('—'),
                        TextEntry::make('nationality')->label('Quốc tịch')->placeholder('—'),
                        TextEntry::make('id_number')->label('Số CCCD')->placeholder('—'),
                        TextEntry::make('id_issued_date')->label('Ngày cấp CCCD')->date('d/m/Y')->placeholder('—'),
                        TextEntry::make('id_issued_place')->label('Nơi cấp CCCD')->placeholder('—'),
                        TextEntry::make('hometown')->label('Quê quán')->placeholder('—'),
                        TextEntry::make('permanent_address')->label('Địa chỉ thường trú')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('current_address')->label('Nơi ở hiện tại')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('province.name')->label('Tỉnh/Thành')->placeholder('—'),
                        TextEntry::make('temple.name')->label('Tự viện')->placeholder('Chưa xác định'),
                        TextEntry::make('monastic_cert_number')->label('Số chứng nhận Tăng ni')->placeholder('—'),
                        TextEntry::make('monastic_cert_date')->label('Ngày cấp chứng nhận')->date('d/m/Y')->placeholder('—'),
                    ]),
                InfolistSection::make('II. Hành đạo & chuyên môn tôn giáo')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('religion')->label('Tôn giáo')->placeholder('—'),
                        TextEntry::make('religious_org')->label('Tổ chức tôn giáo')->placeholder('—'),
                        TextEntry::make('sect')->label('Hệ phái/Dòng tu')->placeholder('—'),
                        TextEntry::make('classification')
                            ->label('Phân loại')
                            // Filament truyền state THÔ (chưa qua cast 'array' của model) vào
                            // formatStateUsing() ở infolist — dùng ->state() đọc thẳng qua
                            // $record->classification (đã cast) để tránh nhận string JSON.
                            ->state(fn (MonasticProfile $record): string => $record->classification
                                ? collect($record->classification)->map(fn ($v) => self::$classificationLabels[$v] ?? $v)->implode(', ')
                                : '—'),
                        TextEntry::make('current_position')->label('Chức vụ/Phẩm vị hiện tại')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('ordination_date')->label('Ngày thụ phong/bổ nhiệm')->date('d/m/Y')->placeholder('—'),
                        TextEntry::make('activity_scope')->label('Phạm vi hoạt động')->placeholder('—'),
                        TextEntry::make('concurrent_position')->label('Chức vụ kiêm nhiệm')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('notes')->label('Ghi chú')->placeholder('—')->columnSpanFull(),
                    ]),
                InfolistSection::make('III. Đào tạo')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('education_level')->label('Trình độ học vấn phổ thông')->placeholder('—'),
                        TextEntry::make('professional_qualification')->label('Trình độ chuyên môn')->placeholder('—'),
                        TextEntry::make('religious_education_level')->label('Trình độ tu học')->placeholder('—'),
                        TextEntry::make('languages')->label('Ngoại ngữ/Tiếng dân tộc')->placeholder('—'),
                        TextEntry::make('training_institutions')->label('Cơ sở đào tạo tôn giáo')->placeholder('—')->columnSpanFull(),
                    ]),
                InfolistSection::make('IV. Hoạt động & bổ nhiệm')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('activity_history')->label('Quá trình hoạt động')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('commendation_discipline')->label('Khen thưởng/Kỷ luật')->placeholder('—'),
                        TextEntry::make('violations')->label('Khiếu kiện, vi phạm')->placeholder('—'),
                        TextEntry::make('congress_term')->label('Nhiệm kỳ đại hội')->placeholder('—'),
                    ]),
                InfolistSection::make('V. Liên hệ & tình trạng')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('phone')->label('Số điện thoại')->placeholder('—'),
                        TextEntry::make('email')->label('Email')->placeholder('—'),
                        TextEntry::make('status')->label('Tình trạng hiện tại')->placeholder('—'),
                    ]),
                InfolistSection::make('Tài liệu gốc')
                    ->schema([
                        TextEntry::make('document.file_name')->label('File gốc')->placeholder('—'),
                        TextEntry::make('document.download_url')
                            ->label('')
                            ->formatStateUsing(fn (): string => 'Tải file gốc')
                            ->url(fn (MonasticProfile $record): ?string => $record->document?->download_url)
                            ->openUrlInNewTab()
                            ->color('warning')
                            ->visible(fn (MonasticProfile $record): bool => filled($record->document?->file_path)),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Họ và tên')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('religious_name')
                    ->label('Pháp danh')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('temple.name')
                    ->label('Tự viện')
                    ->searchable()
                    ->placeholder('Chưa xác định'),
                Tables\Columns\TextColumn::make('province.name')
                    ->label('Tỉnh/Thành')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('current_position')
                    ->label('Chức vụ/Phẩm vị')
                    ->limit(40)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Điện thoại')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Tình trạng')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('province_id')
                    ->label('Tỉnh/Thành')
                    ->relationship('province', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('temple_id')
                    ->label('Tự viện')
                    ->relationship('temple', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListMonasticProfiles::route('/'),
            'create' => Pages\CreateMonasticProfile::route('/create'),
            'view'   => Pages\ViewMonasticProfile::route('/{record}'),
            'edit'   => Pages\EditMonasticProfile::route('/{record}/edit'),
        ];
    }
}
