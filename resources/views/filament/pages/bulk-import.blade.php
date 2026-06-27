<x-filament-panels::page>

    @if(! $this->imported)
    <div>
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button
                wire:click="import"
                wire:loading.attr="disabled"
                icon="heroicon-o-arrow-up-tray"
                size="lg"
            >
                <span wire:loading.remove wire:target="import">Giải nén & Import</span>
                <span wire:loading wire:target="import">Đang xử lý ZIP...</span>
            </x-filament::button>
        </div>
    </div>
    @else
    <x-filament::section icon="heroicon-o-check-circle" icon-color="success">
        <x-slot name="heading">Import thành công!</x-slot>
        <x-slot name="description">
            Đã đẩy {{ $this->fileCount }} file vào hàng đợi. Hệ thống đang xử lý nền — AI sẽ phân tích từng file và tạo dữ liệu tự động.
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

    <x-filament-actions::modals />

</x-filament-panels::page>
