<x-filament-panels::page>

    {{-- Upload form --}}
    @if(!$this->analyzing && empty($this->previews))
    <x-filament::section>
        <x-slot name="heading">Chọn phiếu để AI phân tích</x-slot>
        <x-slot name="description">
            Gemini sẽ đọc nội dung phiếu, tự nhận diện thông tin theo mẫu Phiếu số 3 và tạo hồ sơ Tăng Ni tự động.
        </x-slot>

        {{ $this->form }}

        <div class="mt-6 flex gap-3">
            <x-filament::button
                wire:click="analyze"
                wire:loading.attr="disabled"
                icon="heroicon-o-sparkles"
                size="lg"
            >
                <span wire:loading.remove wire:target="analyze">Phân tích bằng AI</span>
                <span wire:loading wire:target="analyze">Đang gửi vào hàng đợi...</span>
            </x-filament::button>
        </div>
    </x-filament::section>
    @endif

    {{-- Progress khi đang phân tích trong queue --}}
    @if($this->analyzing)
    <x-filament::section wire:poll.3s="checkAnalysis">
        <x-slot name="heading">Đang phân tích bằng AI...</x-slot>
        <x-slot name="description">
            Gemini đang đọc từng phiếu trong hàng đợi. Trang sẽ tự cập nhật khi hoàn tất.
        </x-slot>
        <div class="py-4 space-y-3">
            <div class="flex justify-between text-sm text-gray-600 mb-1">
                <span>Tiến độ</span>
                <span>{{ $this->doneFiles }} / {{ $this->totalFiles }} file</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                <div class="bg-primary-600 h-3 rounded-full transition-all duration-500"
                    style="width: {{ $this->totalFiles > 0 ? round($this->doneFiles / $this->totalFiles * 100) : 0 }}%">
                </div>
            </div>
            <p class="text-xs text-gray-400 text-center">
                Mỗi file mất khoảng 10–30 giây tuỳ độ dài phiếu.
            </p>
        </div>
    </x-filament::section>
    @endif

    {{-- Preview kết quả AI --}}
    @if(!empty($this->previews))
    <div class="space-y-4">
        <x-filament::section>
            <x-slot name="heading">Kết quả phân tích — Xác nhận trước khi tạo hồ sơ</x-slot>
            <x-slot name="description">
                AI đã nhận diện thông tin bên dưới. Sau khi tạo hồ sơ, bạn vẫn có thể mở từng người để bổ sung đầy đủ các trường còn lại.
            </x-slot>

            <div class="space-y-4">
                @foreach($this->previews as $i => $preview)
                <div class="rounded-xl border {{ $preview['_error'] ? 'border-red-200 bg-red-50' : 'border-gray-200 bg-gray-50' }} p-4">

                    {{-- File header --}}
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-8 h-8 rounded-lg {{ $preview['_file_type'] === 'pdf' ? 'bg-red-100' : 'bg-blue-100' }} flex items-center justify-center">
                            <span class="text-xs font-bold {{ $preview['_file_type'] === 'pdf' ? 'text-red-600' : 'text-blue-600' }}">
                                {{ strtoupper($preview['_file_type']) }}
                            </span>
                        </div>
                        <div>
                            <p class="font-medium text-sm text-gray-800">{{ $preview['_file_name'] }}</p>
                            @if($preview['_error'])
                            <p class="text-xs text-red-500">Lỗi: {{ $preview['_error'] }}</p>
                            @endif
                        </div>
                    </div>

                    @if(!$preview['_error'])
                    <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
                        <div>
                            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">Họ và tên khai sinh</label>
                            <input
                                wire:model="previews.{{ $i }}.full_name"
                                type="text"
                                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">Pháp danh</label>
                            <input
                                wire:model="previews.{{ $i }}.religious_name"
                                type="text"
                                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">Giới tính</label>
                            <select wire:model="previews.{{ $i }}.gender"
                                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                                <option value="nam">Nam</option>
                                <option value="nu">Nữ</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">Chùa / Tự viện</label>
                            <select
                                wire:model="previews.{{ $i }}.temple_id"
                                class="mt-1 w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 {{ empty($preview['temple_id']) ? 'border-amber-400 bg-amber-50' : 'border-gray-300' }}"
                            >
                                <option value="">-- Chưa gán chùa --</option>
                                @foreach($this->temples as $temple)
                                <option value="{{ $temple->id }}">{{ $temple->name }}</option>
                                @endforeach
                            </select>
                            @if(empty($preview['temple_id']))
                            <p class="mt-1 text-xs text-amber-600">
                                AI nhận diện "{{ $preview['temple_name'] ?? '(không rõ)' }}" — không khớp chùa nào trong hệ thống. Vui lòng chọn chùa đúng hoặc để trống.
                            </p>
                            @endif
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">Tỉnh/Thành phố quản lý</label>
                            <select
                                wire:model="previews.{{ $i }}.province_id"
                                class="mt-1 w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 {{ empty($preview['province_id']) ? 'border-amber-400 bg-amber-50' : 'border-gray-300' }}"
                            >
                                <option value="">-- Chọn tỉnh/thành --</option>
                                @foreach($this->provinces->groupBy('region') as $region => $items)
                                <optgroup label="{{ $region }}">
                                    @foreach($items as $province)
                                    <option value="{{ $province->id }}">{{ $province->name }}</option>
                                    @endforeach
                                </optgroup>
                                @endforeach
                            </select>
                            @if(empty($preview['province_id']))
                            <p class="mt-1 text-xs text-amber-600">
                                AI nhận diện "{{ $preview['province_name'] ?? '(không rõ)' }}" — không khớp danh sách 34 tỉnh/thành hiện hành. Vui lòng chọn tỉnh đúng.
                            </p>
                            @endif
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">Phẩm trật</label>
                            <input
                                wire:model="previews.{{ $i }}.rank"
                                type="text"
                                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">Chức vụ hiện tại</label>
                            <input
                                wire:model="previews.{{ $i }}.current_position"
                                type="text"
                                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">Điện thoại</label>
                            <input
                                wire:model="previews.{{ $i }}.phone"
                                type="text"
                                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                            />
                        </div>
                    </div>

                    <p class="mt-3 text-xs text-gray-500">
                        AI cũng đã trích xuất thêm các thông tin khác (ngày sinh, quê quán, giấy tờ tùy thân, quá trình đào tạo, lịch sử hoạt động...) — sẽ được lưu kèm khi tạo hồ sơ. Bạn có thể mở hồ sơ sau khi tạo để xem và chỉnh sửa đầy đủ.
                    </p>
                    @endif
                </div>
                @endforeach
            </div>

            <div class="mt-6 flex gap-3">
                <x-filament::button
                    wire:click="import"
                    wire:loading.attr="disabled"
                    icon="heroicon-o-arrow-down-tray"
                    size="lg"
                >
                    <span wire:loading.remove wire:target="import">Xác nhận tạo hồ sơ ({{ count($this->previews) }} người)</span>
                    <span wire:loading wire:target="import">Đang tạo...</span>
                </x-filament::button>

                <x-filament::button
                    wire:click="cancelPreview"
                    color="gray"
                    icon="heroicon-o-x-mark"
                    size="lg"
                >
                    Hủy
                </x-filament::button>
            </div>
        </x-filament::section>
    </div>
    @endif

    <x-filament-actions::modals />

</x-filament-panels::page>
