<?php

namespace App\Filament\Resources\ProvinceResource\RelationManagers;

use App\Filament\Resources\TempleResource;
use App\Models\Temple;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TemplesRelationManager extends RelationManager
{
    protected static string $relationship = 'temples';
    protected static ?string $title = 'Chùa / Tự viện';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Tên chùa')->searchable(),
                Tables\Columns\TextColumn::make('type')->label('Loại')->badge()
                    ->formatStateUsing(fn ($state) => Temple::$typeLabels[$state] ?? $state),
                Tables\Columns\TextColumn::make('address')->label('Địa chỉ')->limit(40)->toggleable(),
                Tables\Columns\TextColumn::make('head_monk')->label('Trụ trì')->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('HĐ')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('viewAll')
                    ->label('Xem tất cả ở trang Chùa/Tự viện')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn () => TempleResource::getUrl('index', [
                        'tableFilters[province_id][value]' => $this->getOwnerRecord()->id,
                    ]))
                    ->openUrlInNewTab(),
            ])
            ->actions([
                Tables\Actions\Action::make('edit')
                    ->label('Sửa')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (Temple $record) => TempleResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('name');
    }
}
