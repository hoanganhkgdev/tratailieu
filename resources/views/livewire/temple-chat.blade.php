<div
    x-data="{ sidebarOpen: false }"
    x-effect="document.body.style.overflow = (sidebarOpen && window.innerWidth < 1024) ? 'hidden' : ''"
    class="flex h-screen overflow-hidden"
>
    {{-- Lớp phủ tối khi mở sidebar trên di động --}}
    <div
        x-show="sidebarOpen"
        x-cloak
        @click="sidebarOpen = false"
        class="fixed inset-0 z-30 bg-black/40 lg:hidden"
        x-transition.opacity
    ></div>

    {{-- Sidebar: dạng drawer trượt trên di động, cố định trên desktop (lg+) --}}
    <aside
        :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        class="fixed inset-y-0 left-0 z-40 flex w-72 shrink-0 -translate-x-full flex-col border-r border-stone-200 bg-stone-50 transition-transform duration-200 ease-out lg:static lg:w-64 lg:translate-x-0"
    >
        <div class="flex items-center justify-between gap-2 p-3">
            <button
                wire:click="newChat"
                @click="sidebarOpen = false"
                class="flex flex-1 items-center gap-2 rounded-lg border border-orange-300 bg-orange-50 px-3 py-2.5 text-sm font-medium text-orange-700 transition hover:bg-orange-100"
            >
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z"/></svg>
                Trò chuyện mới
            </button>
            <button @click="sidebarOpen = false" class="shrink-0 rounded-lg p-2 text-stone-500 hover:bg-stone-200 lg:hidden">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto px-2 pb-3">
            <p class="px-2 pb-1 pt-2 text-xs font-medium uppercase tracking-wide text-stone-400">Lịch sử</p>
            <div class="flex flex-col gap-0.5">
                @forelse ($conversations as $conversation)
                    <div class="group flex items-center gap-1">
                        <button
                            wire:click="selectConversation({{ $conversation->id }})"
                            @click="sidebarOpen = false"
                            @class([
                                'flex-1 truncate rounded-lg px-2.5 py-2 text-left text-sm transition',
                                'bg-orange-100 text-orange-800' => $conversation->id === $conversationId,
                                'text-stone-600 hover:bg-stone-200' => $conversation->id !== $conversationId,
                            ])
                        >
                            {{ $conversation->title ?: 'Trò chuyện' }}
                        </button>
                        <button
                            wire:click="deleteConversation({{ $conversation->id }})"
                            wire:confirm="Xoá cuộc trò chuyện này?"
                            class="shrink-0 rounded-md p-1.5 text-stone-400 hover:bg-stone-200 hover:text-red-600 lg:hidden lg:group-hover:block"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 006 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 10.23 1.482l.149-.022.841 10.518A2.75 2.75 0 007.596 19h4.807a2.75 2.75 0 002.742-2.53l.841-10.52.149.023a.75.75 0 00.23-1.482A41.03 41.03 0 0014 4.193V3.75A2.75 2.75 0 0011.25 1h-2.5zM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4zM8.58 7.72a.75.75 0 00-1.5.06l.3 7.5a.75.75 0 101.5-.06l-.3-7.5zm4.34.06a.75.75 0 10-1.5-.06l-.3 7.5a.75.75 0 101.5.06l.3-7.5z" clip-rule="evenodd"/></svg>
                        </button>
                    </div>
                @empty
                    <p class="px-2.5 py-2 text-sm text-stone-400">Chưa có cuộc trò chuyện nào.</p>
                @endforelse
            </div>
        </div>

        <div class="border-t border-stone-200 p-3 text-xs text-stone-500">
            {{ auth()->user()->name }}
        </div>
    </aside>

    {{-- Main --}}
    <div class="flex min-w-0 flex-1 flex-col bg-white">
        <header class="flex items-center gap-3 border-b border-stone-200 px-3 py-3 sm:px-6">
            <button @click="sidebarOpen = true" class="shrink-0 rounded-lg p-2 text-stone-500 hover:bg-stone-100 lg:hidden">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
            </button>
            <div class="min-w-0">
                <h1 class="truncate text-sm font-semibold text-stone-900">Tra cứu tự viện</h1>
                <p class="truncate text-xs text-stone-500">Hỏi theo tên chùa, địa chỉ, trụ trì, số điện thoại...</p>
            </div>
        </header>

        <div
            class="flex-1 overflow-y-auto px-3 py-6 sm:px-6"
            x-init="$wire.on('message-sent', () => $nextTick(() => { $el.scrollTop = $el.scrollHeight }))"
        >
            <div class="mx-auto flex max-w-2xl flex-col gap-6">
                @forelse ($messages as $message)
                    @if ($message->role === 'user')
                        <div class="flex justify-end">
                            <div class="max-w-[85%] break-words rounded-2xl bg-orange-600 px-4 py-2.5 text-sm text-white sm:max-w-[80%]">
                                {{ $message->content }}
                            </div>
                        </div>
                    @else
                        <div class="flex flex-col gap-3">
                            <p class="whitespace-pre-line break-words text-sm leading-relaxed text-stone-800">{{ $message->content }}</p>

                            @if (!empty($message->temples))
                                <div class="flex flex-col gap-2">
                                    @foreach ($message->temples as $temple)
                                        <div class="flex flex-col gap-1 rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-xs sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                                            <span class="min-w-0 truncate text-stone-600">
                                                <span class="font-medium text-stone-900">{{ $temple['code'] }} — {{ $temple['name'] }}</span>
                                                <span class="text-stone-500">({{ $temple['province'] ?? 'chưa rõ tỉnh' }})</span>
                                            </span>
                                            @if ($temple['download_url'])
                                                <a href="{{ $temple['download_url'] }}" target="_blank" class="shrink-0 font-medium text-orange-600 hover:underline">
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
                        <p class="text-lg font-medium text-stone-800">Hỏi gì về tự viện cũng được</p>
                        <p class="text-sm text-stone-500">Ví dụ: "Chùa An Lạc ở đâu?", "Trụ trì chùa Bửu Sơn là ai?", "SĐT chùa mã 0004?"</p>
                    </div>
                @endforelse

                <div wire:loading wire:target="ask" class="flex items-center gap-2 text-sm text-stone-500">
                    <svg class="h-4 w-4 animate-spin text-orange-600" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    Đang tìm...
                </div>
            </div>
        </div>

        <div class="border-t border-stone-200 px-3 py-3 sm:px-6 sm:py-4">
            <form
                wire:submit="ask"
                @submit="$wire.dispatch('message-sent')"
                class="mx-auto flex max-w-2xl items-end gap-2 rounded-2xl border border-stone-300 bg-white p-2 shadow-sm focus-within:border-orange-500"
            >
                <textarea
                    wire:model="question"
                    wire:keydown.enter.prevent="ask"
                    rows="1"
                    placeholder="Nhập câu hỏi..."
                    class="min-w-0 flex-1 resize-none bg-transparent px-2 py-1.5 text-sm text-stone-900 placeholder:text-stone-400 focus:outline-none"
                ></textarea>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="ask"
                    class="shrink-0 rounded-lg bg-orange-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-orange-500 disabled:opacity-50"
                >
                    Gửi
                </button>
            </form>
        </div>
    </div>
</div>
