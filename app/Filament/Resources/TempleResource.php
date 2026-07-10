<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TempleResource\Pages;
use App\Filament\Resources\TempleResource\RelationManagers;
use App\Models\Temple;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TempleResource extends Resource
{
    protected static ?string $model = Temple::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Tự viện';

    protected static ?string $navigationGroup = 'Tự viện';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'tự viện';

    protected static ?string $pluralModelLabel = 'Tự viện';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('province_id')
                    ->label('Tỉnh/Thành')
                    ->relationship('province', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('code')
                    ->label('Mã tự viện')
                    ->required()
                    ->maxLength(20),
                Forms\Components\TextInput::make('name')
                    ->label('Tên tự viện')
                    ->required()
                    ->maxLength(255)
                    ->columnSpan(2),
                Forms\Components\Select::make('type')
                    ->label('Loại hình')
                    ->options(Temple::$typeLabels)
                    ->required()
                    ->default('chua'),
                Forms\Components\TextInput::make('address')
                    ->label('Địa chỉ')
                    ->maxLength(255)
                    ->columnSpan(2),
                Forms\Components\TextInput::make('head_monk')
                    ->label('Trụ trì')
                    ->maxLength(255),
                Forms\Components\TextInput::make('phone')
                    ->label('Số điện thoại')
                    ->tel()
                    ->maxLength(20),
                Forms\Components\Toggle::make('is_active')
                    ->label('Đang hoạt động')
                    ->default(true),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Mã')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Tên tự viện')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('province.name')
                    ->label('Tỉnh/Thành')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('type')
                    ->label('Loại hình')
                    ->formatStateUsing(fn (string $state): string => Temple::$typeLabels[$state] ?? $state),
                Tables\Columns\TextColumn::make('head_monk')
                    ->label('Trụ trì')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Điện thoại')
                    ->searchable(),
                Tables\Columns\TextColumn::make('monastics_count')
                    ->label('Số chức sắc')
                    ->counts('monastics'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Hoạt động')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('province_id')
                    ->label('Tỉnh/Thành')
                    ->relationship('province', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Loại hình')
                    ->options(Temple::$typeLabels),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->label('Tải file gốc')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Temple $record): ?string => $record->latestDocument?->download_url)
                    ->openUrlInNewTab()
                    ->visible(fn (Temple $record): bool => filled($record->latest_document_id)),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MonasticsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTemples::route('/'),
            'create' => Pages\CreateTemple::route('/create'),
            'view'   => Pages\ViewTemple::route('/{record}'),
            'edit'   => Pages\EditTemple::route('/{record}/edit'),
        ];
    }
}
