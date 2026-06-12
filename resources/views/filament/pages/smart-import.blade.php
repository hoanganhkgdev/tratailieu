<x-filament-panels::page>

    {{-- Upload form --}}
    @if(empty($this->previews))
    <x-filament::section>
        <x-slot name="heading">Chọn file để AI phân tích</x-slot>
        <x-slot name="description">
            Gemini sẽ đọc nội dung, tự nhận diện tên chùa, tỉnh thành và tạo hồ sơ tự động.
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
                <span wire:loading wire:target="analyze">Đang phân tích...</span>
            </x-filament::button>
        </div>
    </x-filament::section>
    @endif

    {{-- Preview kết quả AI --}}
    @if(!empty($this->previews))
    <div class="space-y-4">
        <x-filament::section>
            <x-slot name="heading">Kết quả phân tích — Xác nhận trước khi import</x-slot>
            <x-slot name="description">
                AI đã nhận diện thông tin bên dưới. Bạn có thể chỉnh sửa trước khi xác nhận.
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
                            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">Tên tài liệu</label>
                            <input
                                wire:model="previews.{{ $i }}.document_title"
                                type="text"
                                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">Tên chùa</label>
                            <input
                                wire:model="previews.{{ $i }}.temple_name"
                                type="text"
                                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">Tỉnh/Thành phố</label>
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
                                AI nhận diện "{{ $preview['province_name'] ?? '(không rõ)' }}" — không khớp danh sách 34 tỉnh/thành hiện hành (có thể do tài liệu ghi địa danh trước sáp nhập). Vui lòng chọn tỉnh đúng.
                            </p>
                            @endif
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">Loại</label>
                            <select wire:model="previews.{{ $i }}.temple_type"
                                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                                <option value="chua">Chùa</option>
                                <option value="tu_vien">Tự viện</option>
                                <option value="tinh_xa">Tịnh xá</option>
                                <option value="thien_vien">Thiền viện</option>
                                <option value="tinh_that">Tịnh thất</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">Trụ trì</label>
                            <input
                                wire:model="previews.{{ $i }}.head_monk"
                                type="text"
                                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                            />
                        </div>
                        <div class="col-span-2 md:col-span-3">
                            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">Mô tả</label>
                            <textarea
                                wire:model="previews.{{ $i }}.document_description"
                                rows="2"
                                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                            ></textarea>
                        </div>
                    </div>
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
                    <span wire:loading.remove wire:target="import">Xác nhận Import ({{ count($this->previews) }} file)</span>
                    <span wire:loading wire:target="import">Đang import...</span>
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
