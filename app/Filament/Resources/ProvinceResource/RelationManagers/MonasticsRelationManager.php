<?php

namespace App\Filament\Resources\ProvinceResource\RelationManagers;

use App\Filament\Resources\MonasticResource;
use App\Models\Monastic;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MonasticsRelationManager extends RelationManager
{
    protected static string $relationship = 'monastics';
    protected static ?string $title = 'Tăng Ni';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('full_name')
            ->columns([
                Tables\Columns\TextColumn::make('full_name')->label('Họ và tên')->searchable(),
                Tables\Columns\TextColumn::make('religious_name')->label('Pháp danh')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('rank')->label('Phẩm trật')->badge()
                    ->formatStateUsing(fn ($state, Monastic $record) => Monastic::rankLabel($record->gender, $state) ?? '—'),
                Tables\Columns\TextColumn::make('temple.name')->label('Chùa / Tự viện')->placeholder('— Chưa gán —')->toggleable(),
                Tables\Columns\TextColumn::make('status')->label('Tình trạng')->badge()
                    ->formatStateUsing(fn ($state) => Monastic::$statusLabels[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'dang_hoat_dong' => 'success',
                        'huu_tri'        => 'gray',
                        'cach_chuc', 'hoan_tuc', 'tan_xuat' => 'danger',
                        'da_chet'        => 'gray',
                        default          => 'gray',
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('viewAll')
                    ->label('Xem tất cả ở trang Tăng Ni')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn () => MonasticResource::getUrl('index', [
                        'tableFilters[province_id][value]' => $this->getOwnerRecord()->id,
                    ]))
                    ->openUrlInNewTab(),
            ])
            ->actions([
                Tables\Actions\Action::make('edit')
                    ->label('Sửa')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (Monastic $record) => MonasticResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('full_name');
    }
}
