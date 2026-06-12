<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MonasticResource\Pages;
use App\Models\Monastic;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;

class MonasticResource extends Resource
{
    protected static ?string $model = Monastic::class;
    protected static ?string $navigationIcon  = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'Tăng ni';
    protected static ?string $modelLabel      = 'Tăng Ni';
    protected static ?string $pluralModelLabel = 'Tăng Ni';
    protected static ?string $navigationGroup = 'Tăng Ni';
    protected static ?int    $navigationSort  = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make()
                ->tabs([

                    Forms\Components\Tabs\Tab::make('Cá nhân')
                        ->icon('heroicon-o-identification')
                        ->schema([
                            Forms\Components\FileUpload::make('photo')
                                ->label('Ảnh chân dung')
                                ->image()->avatar()
                                ->directory('monastics')
                                ->columnSpanFull(),
                            Forms\Components\TextInput::make('full_name')
                                ->label('Họ và tên khai sinh')
                                ->required(),
                            Forms\Components\TextInput::make('religious_name')
                                ->label('Pháp danh / Tên trong tôn giáo'),
                            Forms\Components\Select::make('gender')
                                ->label('Giới tính')
                                ->options(Monastic::$genderLabels)
                                ->live()
                                ->required(),
                            Forms\Components\DatePicker::make('date_of_birth')
                                ->label('Ngày sinh')
                                ->displayFormat('d/m/Y'),
                            Forms\Components\TextInput::make('ethnicity')
                                ->label('Dân tộc'),
                            Forms\Components\TextInput::make('nationality')
                                ->label('Quốc tịch')
                                ->default('Việt Nam'),
                            Forms\Components\Select::make('id_type')
                                ->label('Loại giấy tờ tùy thân')
                                ->options(Monastic::$idTypeLabels),
                            Forms\Components\TextInput::make('id_number')
                                ->label('Số giấy tờ'),
                            Forms\Components\DatePicker::make('id_issued_date')
                                ->label('Ngày cấp')
                                ->displayFormat('d/m/Y'),
                            Forms\Components\TextInput::make('id_issued_place')
                                ->label('Nơi cấp'),
                            Forms\Components\TextInput::make('hometown')
                                ->label('Quê quán'),
                            Forms\Components\TextInput::make('permanent_address')
                                ->label('Địa chỉ thường trú'),
                            Forms\Components\TextInput::make('current_address')
                                ->label('Nơi ở hiện tại'),
                            Forms\Components\TextInput::make('monastic_cert_number')
                                ->label('Số chứng nhận Tăng Ni'),
                            Forms\Components\DatePicker::make('monastic_cert_date')
                                ->label('Ngày cấp chứng nhận')
                                ->displayFormat('d/m/Y'),
                        ])->columns(3),

                    Forms\Components\Tabs\Tab::make('Hành đạo')
                        ->icon('heroicon-o-building-library')
                        ->schema([
                            Forms\Components\Select::make('temple_id')
                                ->label('Chùa / Tự viện')
                                ->relationship('temple', 'name')
                                ->searchable()->preload()->live()
                                ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                    if ($state && blank($get('province_id'))) {
                                        $set('province_id', \App\Models\Temple::find($state)?->province_id);
                                    }
                                }),
                            Forms\Components\Select::make('province_id')
                                ->label('Tỉnh/Thành phố quản lý')
                                ->relationship('province', 'name')
                                ->searchable()->preload()
                                ->helperText('Tự động điền theo chùa đã chọn, có thể sửa lại nếu cần.'),
                            Forms\Components\TextInput::make('religion')
                                ->label('Tôn giáo')
                                ->default('Phật giáo'),
                            Forms\Components\TextInput::make('religious_organization')
                                ->label('Tổ chức tôn giáo'),
                            Forms\Components\TextInput::make('sect')
                                ->label('Hệ phái / Dòng tu'),
                            Forms\Components\Select::make('rank')
                                ->label('Phẩm trật')
                                ->options(fn (Forms\Get $get) => Monastic::$ranksByGender[$get('gender')] ?? [])
                                ->disabled(fn (Forms\Get $get) => blank($get('gender')))
                                ->placeholder('-- Chọn giới tính trước --'),
                            Forms\Components\TextInput::make('current_position')
                                ->label('Chức vụ / Phẩm vị hiện tại'),
                            Forms\Components\DatePicker::make('appointment_date')
                                ->label('Ngày thụ phong / bổ nhiệm')
                                ->displayFormat('d/m/Y'),
                            Forms\Components\TextInput::make('concurrent_position')
                                ->label('Chức vụ kiêm nhiệm'),
                            Forms\Components\Select::make('activity_scope')
                                ->label('Phạm vi hoạt động')
                                ->options(Monastic::$activityScopeLabels)
                                ->live(),
                            Forms\Components\TextInput::make('activity_scope_detail')
                                ->label('Chi tiết phạm vi hoạt động')
                                ->visible(fn (Forms\Get $get) => in_array($get('activity_scope'), ['mot_so_tinh', 'mot_tinh']))
                                ->placeholder('VD: An Giang, Tây Ninh...'),
                            Forms\Components\CheckboxList::make('classifications')
                                ->label('Phân loại')
                                ->options(Monastic::$classificationLabels)
                                ->columns(3)
                                ->columnSpanFull(),
                            Forms\Components\Textarea::make('notes')
                                ->label('Ghi chú')
                                ->rows(3)
                                ->columnSpanFull(),
                        ])->columns(3),

                    Forms\Components\Tabs\Tab::make('Đào tạo')
                        ->icon('heroicon-o-academic-cap')
                        ->schema([
                            Forms\Components\TextInput::make('education_level')
                                ->label('Trình độ học vấn phổ thông'),
                            Forms\Components\TextInput::make('professional_qualification')
                                ->label('Trình độ chuyên môn'),
                            Forms\Components\TextInput::make('buddhist_education_level')
                                ->label('Trình độ Phật học / tu học'),
                            Forms\Components\TextInput::make('languages')
                                ->label('Ngoại ngữ / tiếng dân tộc'),
                            Forms\Components\Textarea::make('training_institutions')
                                ->label('Cơ sở đào tạo đã theo học')
                                ->rows(3)
                                ->columnSpanFull(),
                        ])->columns(2),

                    Forms\Components\Tabs\Tab::make('Hoạt động')
                        ->icon('heroicon-o-clipboard-document-list')
                        ->schema([
                            Forms\Components\Repeater::make('activities')
                                ->label('')
                                ->relationship('activities')
                                ->schema([
                                    Forms\Components\DatePicker::make('from_date')
                                        ->label('Từ ngày')
                                        ->displayFormat('d/m/Y'),
                                    Forms\Components\DatePicker::make('to_date')
                                        ->label('Đến ngày')
                                        ->displayFormat('d/m/Y'),
                                    Forms\Components\TextInput::make('place')
                                        ->label('Nơi hoạt động'),
                                    Forms\Components\TextInput::make('position')
                                        ->label('Chức vụ đảm nhận'),
                                    Forms\Components\TextInput::make('term_period')
                                        ->label('Nhiệm kỳ')
                                        ->placeholder('VD: 2020 - 2025'),
                                    Forms\Components\Textarea::make('commendation')
                                        ->label('Khen thưởng')
                                        ->rows(2),
                                    Forms\Components\Textarea::make('violation')
                                        ->label('Kỷ luật / vi phạm')
                                        ->rows(2),
                                ])
                                ->columns(3)
                                ->collapsed()
                                ->itemLabel(fn (array $state): ?string => trim(($state['position'] ?? '') . ($state['place'] ? ' — ' . $state['place'] : '')))
                                ->addActionLabel('Thêm giai đoạn hoạt động')
                                ->columnSpanFull(),
                        ]),

                    Forms\Components\Tabs\Tab::make('Liên hệ')
                        ->icon('heroicon-o-phone')
                        ->schema([
                            Forms\Components\TextInput::make('phone')
                                ->label('Điện thoại')
                                ->tel(),
                            Forms\Components\TextInput::make('email')
                                ->label('Email')
                                ->email(),
                            Forms\Components\Select::make('status')
                                ->label('Tình trạng')
                                ->options(Monastic::$statusLabels)
                                ->live()
                                ->default('dang_hoat_dong')
                                ->required(),
                            Forms\Components\DatePicker::make('death_date')
                                ->label('Ngày qua đời')
                                ->displayFormat('d/m/Y')
                                ->visible(fn (Forms\Get $get) => $get('status') === 'da_chet'),
                        ])->columns(2),

                ])
                ->persistTabInQueryString()
                ->columnSpanFull(),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Split::make([

                // Sidebar: ảnh + tóm tắt
                Infolists\Components\Section::make()
                    ->schema([
                        Infolists\Components\ImageEntry::make('photo')
                            ->label('')
                            ->circular()
                            ->size(100)
                            ->alignCenter()
                            ->defaultImageUrl(fn ($record) => 'https://ui-avatars.com/api/?name=' . urlencode($record->full_name) . '&size=100&background=d97706&color=fff'),
                        Infolists\Components\TextEntry::make('full_name')
                            ->label('')
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                            ->weight(FontWeight::Bold)
                            ->alignCenter(),
                        Infolists\Components\TextEntry::make('religious_name')
                            ->label('')
                            ->alignCenter()
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('rank')
                            ->label('Phẩm trật')
                            ->badge()
                            ->alignCenter()
                            ->formatStateUsing(fn ($state, $record) => Monastic::rankLabel($record->gender, $state) ?? '—')
                            ->color(fn ($state) => match ($state) {
                                'hoa_thuong', 'ni_truong' => 'warning',
                                'thuong_toa', 'ni_su'     => 'primary',
                                'dai_duc', 'su_co'        => 'success',
                                default                   => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('status')
                            ->label('Tình trạng')
                            ->badge()
                            ->alignCenter()
                            ->formatStateUsing(fn ($state) => Monastic::$statusLabels[$state] ?? $state)
                            ->color(fn ($state) => match ($state) {
                                'dang_hoat_dong' => 'success',
                                'huu_tri'        => 'info',
                                default          => 'danger',
                            }),
                        Infolists\Components\TextEntry::make('current_position')
                            ->label('Chức vụ')
                            ->alignCenter()
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('temple.name')
                            ->label('Tự viện')
                            ->icon('heroicon-m-building-library')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('province.name')
                            ->label('Tỉnh/Thành')
                            ->icon('heroicon-m-map-pin')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('phone')
                            ->label('Điện thoại')
                            ->icon('heroicon-m-phone')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('email')
                            ->label('Email')
                            ->icon('heroicon-m-envelope')
                            ->placeholder('—'),
                    ])
                    ->grow(false),

                // Main: tabs chi tiết
                Infolists\Components\Tabs::make()
                    ->tabs([
                        Infolists\Components\Tabs\Tab::make('Định danh')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                Infolists\Components\TextEntry::make('date_of_birth')
                                    ->label('Ngày sinh')
                                    ->date('d/m/Y')
                                    ->placeholder('—'),
                                Infolists\Components\TextEntry::make('gender')
                                    ->label('Giới tính')
                                    ->formatStateUsing(fn ($state) => Monastic::$genderLabels[$state] ?? $state),
                                Infolists\Components\TextEntry::make('ethnicity')
                                    ->label('Dân tộc')->placeholder('—'),
                                Infolists\Components\TextEntry::make('nationality')
                                    ->label('Quốc tịch')->placeholder('—'),
                                Infolists\Components\TextEntry::make('id_type')
                                    ->label('Loại giấy tờ')
                                    ->formatStateUsing(fn ($state) => Monastic::$idTypeLabels[$state] ?? $state)
                                    ->placeholder('—'),
                                Infolists\Components\TextEntry::make('id_number')
                                    ->label('Số giấy tờ')->placeholder('—'),
                                Infolists\Components\TextEntry::make('id_issued_date')
                                    ->label('Ngày cấp')->date('d/m/Y')->placeholder('—'),
                                Infolists\Components\TextEntry::make('id_issued_place')
                                    ->label('Nơi cấp')->placeholder('—'),
                                Infolists\Components\TextEntry::make('monastic_cert_number')
                                    ->label('Số chứng nhận Tăng Ni')->placeholder('—'),
                                Infolists\Components\TextEntry::make('monastic_cert_date')
                                    ->label('Ngày cấp chứng nhận')->date('d/m/Y')->placeholder('—'),
                                Infolists\Components\TextEntry::make('hometown')
                                    ->label('Quê quán')->placeholder('—')->columnSpanFull(),
                                Infolists\Components\TextEntry::make('permanent_address')
                                    ->label('Thường trú')->placeholder('—')->columnSpanFull(),
                                Infolists\Components\TextEntry::make('current_address')
                                    ->label('Nơi ở hiện tại')->placeholder('—')->columnSpanFull(),
                            ])->columns(2),

                        Infolists\Components\Tabs\Tab::make('Hành đạo')
                            ->icon('heroicon-o-building-library')
                            ->schema([
                                Infolists\Components\TextEntry::make('religion')
                                    ->label('Tôn giáo')->placeholder('—'),
                                Infolists\Components\TextEntry::make('religious_organization')
                                    ->label('Tổ chức tôn giáo')->placeholder('—'),
                                Infolists\Components\TextEntry::make('sect')
                                    ->label('Hệ phái / Dòng tu')->placeholder('—'),
                                Infolists\Components\TextEntry::make('appointment_date')
                                    ->label('Ngày thụ phong / bổ nhiệm')->date('d/m/Y')->placeholder('—'),
                                Infolists\Components\TextEntry::make('concurrent_position')
                                    ->label('Chức vụ kiêm nhiệm')->placeholder('—'),
                                Infolists\Components\TextEntry::make('activity_scope')
                                    ->label('Phạm vi hoạt động')
                                    ->formatStateUsing(fn ($state) => Monastic::$activityScopeLabels[$state] ?? $state)
                                    ->placeholder('—'),
                                Infolists\Components\TextEntry::make('activity_scope_detail')
                                    ->label('Chi tiết phạm vi')->placeholder('—'),
                                Infolists\Components\TextEntry::make('classifications')
                                    ->label('Phân loại')
                                    ->badge()
                                    ->formatStateUsing(fn ($state) => Monastic::$classificationLabels[$state] ?? $state)
                                    ->placeholder('—'),
                                Infolists\Components\TextEntry::make('notes')
                                    ->label('Ghi chú')->placeholder('—')->columnSpanFull(),
                            ])->columns(2),

                        Infolists\Components\Tabs\Tab::make('Đào tạo')
                            ->icon('heroicon-o-academic-cap')
                            ->schema([
                                Infolists\Components\TextEntry::make('education_level')
                                    ->label('Học vấn phổ thông')->placeholder('—'),
                                Infolists\Components\TextEntry::make('buddhist_education_level')
                                    ->label('Trình độ Phật học')->placeholder('—'),
                                Infolists\Components\TextEntry::make('professional_qualification')
                                    ->label('Chuyên môn')->placeholder('—'),
                                Infolists\Components\TextEntry::make('languages')
                                    ->label('Ngoại ngữ')->placeholder('—'),
                                Infolists\Components\TextEntry::make('training_institutions')
                                    ->label('Cơ sở đào tạo')->placeholder('—')->columnSpanFull(),
                            ])->columns(2),

                        Infolists\Components\Tabs\Tab::make('Hoạt động')
                            ->icon('heroicon-o-clipboard-document-list')
                            ->schema([
                                Infolists\Components\RepeatableEntry::make('activities')
                                    ->label('')
                                    ->schema([
                                        Infolists\Components\TextEntry::make('from_date')
                                            ->label('Từ ngày')->date('d/m/Y')->placeholder('—'),
                                        Infolists\Components\TextEntry::make('to_date')
                                            ->label('Đến ngày')->date('d/m/Y')->placeholder('—'),
                                        Infolists\Components\TextEntry::make('place')
                                            ->label('Nơi hoạt động')->placeholder('—'),
                                        Infolists\Components\TextEntry::make('position')
                                            ->label('Chức vụ')->placeholder('—'),
                                        Infolists\Components\TextEntry::make('term_period')
                                            ->label('Nhiệm kỳ')->placeholder('—'),
                                        Infolists\Components\TextEntry::make('commendation')
                                            ->label('Khen thưởng')->placeholder('—'),
                                        Infolists\Components\TextEntry::make('violation')
                                            ->label('Kỷ luật')->placeholder('—'),
                                    ])
                                    ->columns(3)
                                    ->columnSpanFull(),
                            ]),
                    ]),

            ])->from('lg'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('stt')
                    ->label('STT')
                    ->rowIndex(),

                Tables\Columns\TextColumn::make('full_name')
                    ->label('Họ và tên')
                    ->description(fn (Monastic $record): string => $record->religious_name ?? '')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Medium),

                Tables\Columns\TextColumn::make('temple.name')
                    ->label('Tự viện / Tỉnh thành')
                    ->description(fn (Monastic $record): string => $record->province?->name ?? $record->temple?->province?->name ?? '')
                    ->searchable()
                    ->sortable()
                    ->placeholder('— Chưa gán —'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Tình trạng')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Monastic::$statusLabels[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'dang_hoat_dong'                    => 'success',
                        'huu_tri'                           => 'info',
                        'cach_chuc', 'hoan_tuc', 'tan_xuat' => 'danger',
                        'da_chet'                           => 'gray',
                        default                             => 'gray',
                    }),
            ])
            ->recordUrl(fn (Monastic $record) => static::getUrl('view', ['record' => $record]))
            ->groups([
                Tables\Grouping\Group::make('province.name')
                    ->label('Tỉnh/Thành phố')
                    ->collapsible(),
                Tables\Grouping\Group::make('rank')
                    ->label('Phẩm trật')
                    ->getTitleFromRecordUsing(fn (Monastic $record) => Monastic::rankLabel($record->gender, $record->rank) ?? '—')
                    ->collapsible(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('gender')
                    ->label('Giới tính')
                    ->options(Monastic::$genderLabels),
                Tables\Filters\SelectFilter::make('rank')
                    ->label('Phẩm trật')
                    ->options(array_merge(...array_values(Monastic::$ranksByGender))),
                Tables\Filters\SelectFilter::make('temple_id')
                    ->label('Tự viện')
                    ->relationship('temple', 'name')
                    ->searchable()->preload(),
                Tables\Filters\SelectFilter::make('province_id')
                    ->label('Tỉnh/Thành')
                    ->relationship('province', 'name')
                    ->searchable()->preload(),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Tình trạng')
                    ->options(Monastic::$statusLabels),
            ])
            ->filtersLayout(Tables\Enums\FiltersLayout::AboveContent)
            ->filtersFormColumns(5)
            ->actions([
                Tables\Actions\ViewAction::make()->label('')->tooltip('Xem chi tiết'),
                Tables\Actions\EditAction::make()->label('')->tooltip('Chỉnh sửa'),
                Tables\Actions\DeleteAction::make()->label('')->tooltip('Xóa'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Xóa đã chọn'),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListMonastics::route('/'),
            'create' => Pages\CreateMonastic::route('/create'),
            'view'   => Pages\ViewMonastic::route('/{record}'),
            'edit'   => Pages\EditMonastic::route('/{record}/edit'),
        ];
    }
}
