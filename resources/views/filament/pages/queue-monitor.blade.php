<x-filament-panels::page wire:poll.5s>

    <div class="grid grid-cols-2 gap-4">
        <x-filament::section>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Đang chờ xử lý</p>
                    <p class="text-3xl font-bold text-amber-600">{{ $this->stats['pending'] }}</p>
                </div>
                <x-heroicon-o-clock class="w-10 h-10 text-amber-300" />
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Job lỗi</p>
                    <p class="text-3xl font-bold text-red-600">{{ $this->stats['failed'] }}</p>
                </div>
                <x-heroicon-o-x-circle class="w-10 h-10 text-red-300" />
            </div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Đang chờ xử lý ({{ $this->pendingJobs->count() }})</x-slot>

        @if($this->pendingJobs->isEmpty())
            <p class="text-sm text-gray-400 text-center py-8">Không có job nào đang chờ.</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 border-b">
                        <th class="py-2 pr-4">Trạng thái</th>
                        <th class="py-2 pr-4">Job</th>
                        <th class="py-2 pr-4">Chi tiết</th>
                        <th class="py-2 pr-4">Lần thử</th>
                        <th class="py-2 pr-4">Tạo lúc</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->pendingJobs as $job)
                    <tr class="border-b border-gray-100">
                        <td class="py-2 pr-4 whitespace-nowrap">
                            @if($job->is_processing)
                                <span class="inline-flex items-center gap-1.5 text-amber-600 font-medium">
                                    <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                                    Đang xử lý
                                </span>
                            @else
                                <span class="text-gray-400">Chờ</span>
                            @endif
                        </td>
                        <td class="py-2 pr-4 font-medium whitespace-nowrap">{{ class_basename($job->job_name) }}</td>
                        <td class="py-2 pr-4 text-gray-600">{{ $job->detail ?? '—' }}</td>
                        <td class="py-2 pr-4">{{ $job->attempts }}</td>
                        <td class="py-2 pr-4 text-gray-400 whitespace-nowrap">{{ $job->created_human }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Job lỗi ({{ $this->failedJobs->count() }})</x-slot>

        @if($this->failedJobs->isNotEmpty())
        <div class="mb-4 flex gap-2">
            <x-filament::button wire:click="retryAll" color="warning" icon="heroicon-o-arrow-path" size="sm">
                Chạy lại tất cả
            </x-filament::button>
            <x-filament::button wire:click="clearFailedJobs" color="danger" icon="heroicon-o-trash" size="sm"
                wire:confirm="Xóa toàn bộ job lỗi?">
                Xóa tất cả
            </x-filament::button>
        </div>
        @endif

        @if($this->failedJobs->isEmpty())
            <p class="text-sm text-gray-400 text-center py-8">Không có job lỗi nào.</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 border-b">
                        <th class="py-2 pr-4">Job</th>
                        <th class="py-2 pr-4">Chi tiết</th>
                        <th class="py-2 pr-4">Lỗi</th>
                        <th class="py-2 pr-4">Thời gian</th>
                        <th class="py-2 pr-4">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->failedJobs as $job)
                    <tr class="border-b border-gray-100">
                        <td class="py-2 pr-4 font-medium whitespace-nowrap">{{ class_basename($job->job_name) }}</td>
                        <td class="py-2 pr-4 text-gray-600 whitespace-nowrap">{{ $job->detail ?? '—' }}</td>
                        <td class="py-2 pr-4 text-red-600 max-w-md">{{ $job->exception_short }}</td>
                        <td class="py-2 pr-4 text-gray-400 whitespace-nowrap">{{ $job->failed_at }}</td>
                        <td class="py-2 pr-4 whitespace-nowrap">
                            <button wire:click="retryJob('{{ $job->uuid }}')" class="text-amber-600 hover:underline mr-3">Chạy lại</button>
                            <button wire:click="deleteFailedJob({{ $job->id }})" class="text-red-600 hover:underline" wire:confirm="Xóa job này?">Xóa</button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </x-filament::section>

</x-filament-panels::page>
