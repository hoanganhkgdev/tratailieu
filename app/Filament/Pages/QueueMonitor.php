<?php

namespace App\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class QueueMonitor extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-queue-list';
    protected static ?string $navigationLabel = 'Hàng đợi';
    protected static ?string $title           = 'Theo dõi hàng đợi (Queue)';
    protected static ?string $navigationGroup = 'Hệ thống';
    protected static ?int    $navigationSort  = 1;
    protected static string  $view            = 'filament.pages.queue-monitor';

    public function getPendingJobsProperty()
    {
        return DB::table('jobs')
            ->orderBy('created_at')
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload, true);
                $job->job_name = $payload['displayName'] ?? 'Unknown';
                $job->detail = $this->extractJobDetail($payload);
                $job->is_processing = ! is_null($job->reserved_at);
                $job->created_human = \Carbon\Carbon::createFromTimestamp($job->created_at)->diffForHumans();
                return $job;
            });
    }

    public function getFailedJobsProperty()
    {
        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(100)
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload, true);
                $job->job_name = $payload['displayName'] ?? 'Unknown';
                $job->detail = $this->extractJobDetail($payload);
                $job->exception_short = \Illuminate\Support\Str::limit(explode("\n", $job->exception)[0], 150);
                return $job;
            });
    }

    /**
     * Giải mã object job đã serialize để lấy tên file / thông tin đang xử lý —
     * payload mặc định của Laravel chỉ có tên class, không đủ để biết job đang
     * chạy file nào, nên cần đọc property private qua Reflection.
     */
    private function extractJobDetail(array $payload): ?string
    {
        $serialized = $payload['data']['command'] ?? null;

        if (! $serialized) {
            return null;
        }

        try {
            $job = unserialize($serialized);
        } catch (\Throwable) {
            return null;
        }

        if (! is_object($job)) {
            return null;
        }

        $reflection = new \ReflectionObject($job);
        $parts = [];

        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($job);

            if (is_string($value) && $value !== '') {
                $parts[] = basename($value);
            } elseif (is_object($value) && method_exists($value, 'getKey')) {
                $parts[] = class_basename($value) . ' #' . $value->getKey();
            }
        }

        return ! empty($parts) ? implode(' · ', $parts) : null;
    }

    public function getStatsProperty(): array
    {
        return [
            'pending' => DB::table('jobs')->count(),
            'failed'  => DB::table('failed_jobs')->count(),
        ];
    }

    public function retryJob(string $uuid): void
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Notification::make()->title('Đã đẩy job vào hàng đợi để chạy lại')->success()->send();
    }

    public function retryAll(): void
    {
        Artisan::call('queue:retry', ['id' => ['all']]);
        Notification::make()->title('Đã đẩy tất cả job lỗi vào hàng đợi để chạy lại')->success()->send();
    }

    public function deleteFailedJob(int $id): void
    {
        DB::table('failed_jobs')->where('id', $id)->delete();
        Notification::make()->title('Đã xóa job lỗi')->success()->send();
    }

    public function clearFailedJobs(): void
    {
        DB::table('failed_jobs')->truncate();
        Notification::make()->title('Đã xóa toàn bộ job lỗi')->success()->send();
    }
}
