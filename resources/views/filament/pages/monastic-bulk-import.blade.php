<x-filament-panels::page>

    @if(! $this->imported)
    <x-filament::section>
        <x-slot name="heading">Upload phiếu thông tin Tăng Ni</x-slot>
        <x-slot name="description">
            Nén thư mục chứa phiếu số 3 (PDF/DOCX) thành file ZIP rồi upload lên. AI sẽ tự phân tích và tạo hồ sơ Tăng Ni.
        </x-slot>

        <div class="space-y-4">
            <div>
                <label class="text-sm font-medium text-gray-700">Tỉnh/Thành phố <span class="text-red-500">*</span></label>
                <select wire:model="provinceId" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <option value="">-- Chọn tỉnh/thành --</option>
                    @foreach($this->provinces as $province)
                        <option value="{{ $province->id }}">{{ $province->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-sm font-medium text-gray-700">File ZIP <span class="text-red-500">*</span></label>
                <input type="file" wire:model="zipFile" accept=".zip" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                @if($zipFile)
                    <p class="mt-1 text-sm text-green-600">✓ File đã chọn: {{ $zipFile->getClientOriginalName() }} ({{ number_format($zipFile->getSize() / 1024 / 1024, 1) }} MB)</p>
                @endif
                <div wire:loading wire:target="zipFile" class="mt-1 text-sm text-amber-600">Đang tải lên...</div>
            </div>
        </div>

        <div class="mt-6">
            <x-filament::button
                wire:click="import"
                wire:loading.attr="disabled"
                wire:target="import"
                icon="heroicon-o-arrow-up-tray"
                size="lg"
            >
                <span wire:loading.remove wire:target="import">Giải nén & Import Tăng Ni</span>
                <span wire:loading wire:target="import">Đang xử lý ZIP...</span>
            </x-filament::button>
        </div>
    </x-filament::section>
    @else
    <x-filament::section icon="heroicon-o-check-circle" icon-color="success">
        <x-slot name="heading">Import thành công!</x-slot>
        <x-slot name="description">
            Đã đẩy {{ $this->fileCount }} phiếu Tăng Ni vào hàng đợi. AI đang phân tích từng phiếu và tạo hồ sơ tự động.
        </x-slot>

        <div class="mt-4">
            <x-filament::button
                wire:click="resetForm"
                icon="heroicon-o-arrow-path"
                color="gray"
            >
                Import thêm tỉnh khác
            </x-filament::button>
        </div>
    </x-filament::section>
    @endif

</x-filament-panels::page>
