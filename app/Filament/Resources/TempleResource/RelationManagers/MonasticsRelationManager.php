<?php

namespace App\Filament\Resources\TempleResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MonasticsRelationManager extends RelationManager
{
    protected static string $relationship = 'monastics';

    protected static ?string $title = 'Chức sắc, chức việc, nhà tu hành';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('full_name')
                    ->label('Họ và tên')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('religious_name')
                    ->label('Pháp danh')
                    ->maxLength(255),
                Forms\Components\TextInput::make('rank')
                    ->label('Giáo phẩm / Giới phẩm')
                    ->maxLength(255),
                Forms\Components\TextInput::make('position')
                    ->label('Chức việc')
                    ->maxLength(255),
                Forms\Components\TextInput::make('birth_year')
                    ->label('Năm sinh')
                    ->numeric()
                    ->minValue(1900)
                    ->maxValue(now()->year),
                Forms\Components\TextInput::make('phone')
                    ->label('Điện thoại')
                    ->tel()
                    ->maxLength(20),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('full_name')
            ->defaultSort('stt')
            ->columns([
                Tables\Columns\TextColumn::make('stt')->label('STT')->sortable(),
                Tables\Columns\TextColumn::make('full_name')->label('Họ và tên')->searchable(),
                Tables\Columns\TextColumn::make('religious_name')->label('Pháp danh')->searchable(),
                Tables\Columns\TextColumn::make('rank')->label('Giáo phẩm/Giới phẩm'),
                Tables\Columns\TextColumn::make('position')->label('Chức việc'),
                Tables\Columns\TextColumn::make('birth_year')->label('Năm sinh'),
                Tables\Columns\TextColumn::make('phone')->label('Điện thoại'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
