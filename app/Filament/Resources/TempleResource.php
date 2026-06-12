<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TempleResource\Pages;
use App\Models\Temple;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class TempleResource extends Resource
{
    protected static ?string $model = Temple::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-library';
    protected static ?string $navigationLabel = 'Tự viện';
    protected static ?string $modelLabel = 'Chùa / Tự viện';
    protected static ?string $pluralModelLabel = 'Chùa / Tự viện';
    protected static ?string $navigationGroup = 'Tự viện';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin cơ bản')->schema([
                Forms\Components\Select::make('province_id')
                    ->label('Tỉnh/Thành phố')
                    ->relationship('province', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Select::make('type')
                    ->label('Loại')
                    ->options(Temple::$typeLabels)
                    ->required(),
                Forms\Components\TextInput::make('name')
                    ->label('Tên')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('slug', Str::slug($state))),
                Forms\Components\TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('head_monk')
                    ->label('Trụ trì'),
                Forms\Components\TextInput::make('phone')
                    ->label('Điện thoại')
                    ->tel(),
                Forms\Components\TextInput::make('established_year')
                    ->label('Năm thành lập')
                    ->numeric()
                    ->minValue(100)
                    ->maxValue(date('Y')),
                Forms\Components\Toggle::make('is_active')
                    ->label('Hoạt động')
                    ->default(true),
            ])->columns(2),

            Forms\Components\Section::make('Thông tin chi tiết')->schema([
                Forms\Components\TextInput::make('address')
                    ->label('Địa chỉ')
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('description')
                    ->label('Mô tả')
                    ->rows(4)
                    ->columnSpanFull(),
                Forms\Components\FileUpload::make('image')
                    ->label('Ảnh đại diện')
                    ->image()
                    ->directory('temples')
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')->label('Ảnh')->circular(),
                Tables\Columns\TextColumn::make('name')->label('Tên')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')->label('Loại')->badge()
                    ->formatStateUsing(fn ($state) => Temple::$typeLabels[$state] ?? $state),
                Tables\Columns\TextColumn::make('province.name')->label('Tỉnh/Thành')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('head_monk')->label('Trụ trì')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('documents_count')->label('Tài liệu')
                    ->counts('documents')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('HĐ')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('province_id')->label('Tỉnh/Thành')
                    ->relationship('province', 'name')->searchable()->preload(),
                Tables\Filters\SelectFilter::make('type')->label('Loại')
                    ->options(Temple::$typeLabels),
                Tables\Filters\TernaryFilter::make('is_active')->label('Trạng thái'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Sửa'),
                Tables\Actions\DeleteAction::make()->label('Xóa'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Xóa đã chọn'),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTemples::route('/'),
            'create' => Pages\CreateTemple::route('/create'),
            'edit'   => Pages\EditTemple::route('/{record}/edit'),
        ];
    }
}
