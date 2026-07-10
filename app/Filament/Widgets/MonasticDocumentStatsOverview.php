<?php

namespace App\Filament\Widgets;

use App\Models\MonasticDocument;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MonasticDocumentStatsOverview extends BaseWidget
{
    protected static ?string $pollingInterval = '3s';

    protected function getStats(): array
    {
        $counts = MonasticDocument::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $pending    = $counts->get('pending', 0);
        $processing = $counts->get('processing', 0);
        $ready      = $counts->get('ready', 0);
        $failed     = $counts->get('failed', 0);
        $total      = $pending + $processing + $ready + $failed;

        return [
            Stat::make('Tổng tài liệu', $total)
                ->color('gray'),
            Stat::make('Chờ xử lý', $pending)
                ->color('gray'),
            Stat::make('Đang xử lý', $processing)
                ->color('warning'),
            Stat::make('Hoàn tất', $ready)
                ->color('success'),
            Stat::make('Lỗi', $failed)
                ->color($failed > 0 ? 'danger' : 'gray')
                ->description($failed > 0 ? 'Cần kiểm tra và xử lý lại' : null),
        ];
    }
}
