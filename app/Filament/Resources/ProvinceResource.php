<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProvinceResource\Pages;
use App\Filament\Resources\ProvinceResource\RelationManagers;
use App\Models\Province;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ProvinceResource extends Resource
{
    protected static ?string $model = Province::class;
    protected static ?string $navigationIcon = 'heroicon-o-map-pin';
    protected static ?string $navigationLabel = 'Tỉnh thành';
    protected static ?string $modelLabel = 'Tỉnh thành';
    protected static ?string $pluralModelLabel = 'Tỉnh thành';
    protected static ?string $navigationGroup = 'Hệ thống';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Tên tỉnh/thành phố')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('slug', Str::slug($state))),
                Forms\Components\TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('code')
                    ->label('Mã tỉnh')
                    ->maxLength(10),
                Forms\Components\Select::make('region')
                    ->label('Miền')
                    ->options([
                        'Miền Bắc'   => 'Miền Bắc',
                        'Miền Trung' => 'Miền Trung',
                        'Miền Nam'   => 'Miền Nam',
                    ]),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Tên tỉnh')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('code')->label('Mã')->sortable(),
                Tables\Columns\TextColumn::make('region')->label('Miền')->badge()
                    ->color(fn ($state) => match ($state) {
                        'Miền Bắc'   => 'info',
                        'Miền Trung' => 'warning',
                        'Miền Nam'   => 'success',
                        default      => 'gray',
                    }),
                Tables\Columns\TextColumn::make('temples_count')->label('Số chùa')
                    ->counts('temples')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('region')->label('Miền')
                    ->options(['Miền Bắc' => 'Miền Bắc', 'Miền Trung' => 'Miền Trung', 'Miền Nam' => 'Miền Nam']),
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

    public static function getRelations(): array
    {
        return [
            RelationManagers\TemplesRelationManager::class,
            RelationManagers\MonasticsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProvinces::route('/'),
            'create' => Pages\CreateProvince::route('/create'),
            'edit'   => Pages\EditProvince::route('/{record}/edit'),
        ];
    }
}
