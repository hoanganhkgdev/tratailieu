<div class="flex h-screen flex-col">
    <header class="flex items-center justify-between border-b border-neutral-800 px-4 py-3 sm:px-6">
        <div>
            <h1 class="text-sm font-semibold text-neutral-100">Tra cứu tự viện</h1>
            <p class="text-xs text-neutral-500">Hỏi theo tên chùa, địa chỉ, trụ trì, số điện thoại hoặc tên chức sắc</p>
        </div>
        @if (count($messages))
            <button
                wire:click="clearHistory"
                class="rounded-md border border-neutral-700 px-3 py-1.5 text-xs text-neutral-300 hover:bg-neutral-800"
            >
                Xoá lịch sử
            </button>
        @endif
    </header>

    <div
        class="flex-1 overflow-y-auto px-4 py-6 sm:px-6"
        x-data
        x-init="$wire.on('message-sent', () => $nextTick(() => { $el.scrollTop = $el.scrollHeight }))"
    >
        <div class="mx-auto flex max-w-2xl flex-col gap-6">
            @forelse ($messages as $message)
                @if ($message['role'] === 'user')
                    <div class="flex justify-end">
                        <div class="max-w-[80%] rounded-2xl bg-neutral-100 px-4 py-2.5 text-sm text-neutral-900">
                            {{ $message['content'] }}
                        </div>
                    </div>
                @else
                    <div class="flex flex-col gap-3">
                        <p class="whitespace-pre-line text-sm leading-relaxed text-neutral-200">{{ $message['content'] }}</p>

                        @if (!empty($message['temples']))
                            <div class="flex flex-col gap-2">
                                @foreach ($message['temples'] as $temple)
                                    <div class="flex items-center justify-between gap-3 rounded-lg border border-neutral-800 bg-neutral-900 px-3 py-2 text-xs">
                                        <span class="text-neutral-300">
                                            <span class="font-medium text-neutral-100">{{ $temple['code'] }} — {{ $temple['name'] }}</span>
                                            <span class="text-neutral-500">({{ $temple['province'] ?? 'chưa rõ tỉnh' }})</span>
                                        </span>
                                        @if ($temple['download_url'])
                                            <a href="{{ $temple['download_url'] }}" target="_blank" class="shrink-0 font-medium text-emerald-400 hover:underline">
                                                Tải file gốc
                                            </a>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            @empty
                <div class="mt-16 flex flex-col items-center gap-2 text-center">
                    <p class="text-lg font-medium text-neutral-300">Hỏi gì về tự viện cũng được</p>
                    <p class="text-sm text-neutral-500">Ví dụ: "Chùa An Lạc ở đâu?", "Trụ trì chùa Bửu Sơn là ai?", "SĐT chùa mã 0004?"</p>
                </div>
            @endforelse

            <div wire:loading wire:target="ask" class="flex items-center gap-2 text-sm text-neutral-500">
                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Đang tìm...
            </div>
        </div>
    </div>

    <div class="border-t border-neutral-800 px-4 py-4 sm:px-6">
        <form
            wire:submit="ask"
            @submit="$wire.dispatch('message-sent')"
            class="mx-auto flex max-w-2xl items-end gap-2 rounded-2xl border border-neutral-700 bg-neutral-900 p-2"
        >
            <textarea
                wire:model="question"
                wire:keydown.enter.prevent="ask"
                rows="1"
                placeholder="Nhập câu hỏi..."
                class="flex-1 resize-none bg-transparent px-2 py-1.5 text-sm text-neutral-100 placeholder:text-neutral-500 focus:outline-none"
            ></textarea>
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="ask"
                class="shrink-0 rounded-lg bg-neutral-100 px-3 py-1.5 text-sm font-medium text-neutral-900 disabled:opacity-50"
            >
                Gửi
            </button>
        </form>
    </div>
</div>
