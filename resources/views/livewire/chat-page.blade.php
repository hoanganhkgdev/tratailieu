<div class="flex h-screen overflow-hidden">

    {{-- Sidebar --}}
    <div class="w-64 bg-amber-900 text-amber-50 flex flex-col shrink-0">
        <div class="p-5 border-b border-amber-800">
            <h1 class="text-lg font-bold leading-tight">Thư viện Phật giáo</h1>
            <p class="text-amber-300 text-xs mt-1">Tra cứu tài liệu bằng AI</p>
        </div>

        <div class="p-4">
            <button
                wire:click="newConversation"
                class="w-full flex items-center gap-2 px-4 py-2.5 rounded-lg bg-amber-800 hover:bg-amber-700 transition text-sm font-medium"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Cuộc trò chuyện mới
            </button>
        </div>

        <div class="flex-1 px-4 overflow-y-auto">
            <p class="text-amber-400 text-xs uppercase tracking-wider mb-2">Gợi ý câu hỏi</p>
            @foreach([
                'Giới thiệu về lịch sử chùa này?',
                'Các hoạt động Phật sự nổi bật?',
                'Danh sách tăng ni trong tài liệu?',
                'Nội dung quy chế chùa?',
            ] as $hint)
            <button
                wire:click="$set('question', '{{ $hint }}')"
                class="w-full text-left text-sm text-amber-200 hover:text-white hover:bg-amber-800 rounded px-3 py-2 mb-1 transition"
            >
                {{ $hint }}
            </button>
            @endforeach
        </div>

        <div class="p-4 border-t border-amber-800 text-xs text-amber-400">
            Powered by Gemini AI
        </div>
    </div>

    {{-- Main Chat --}}
    <div class="flex-1 flex flex-col overflow-hidden">

        {{-- Header --}}
        <div class="bg-white border-b px-6 py-4 flex items-center gap-3 shadow-sm">
            <div class="w-9 h-9 rounded-full bg-amber-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                </svg>
            </div>
            <div>
                <p class="font-semibold text-gray-800">Trợ lý tra cứu tài liệu</p>
                <p class="text-xs text-gray-400">Hỏi bất kỳ điều gì về tài liệu Phật giáo</p>
            </div>
        </div>

        {{-- Messages --}}
        <div class="flex-1 overflow-y-auto px-6 py-4 space-y-4" id="chat-messages">

            @if(empty($messages))
            <div class="flex flex-col items-center justify-center h-full text-center text-gray-400">
                <svg class="w-16 h-16 mb-4 text-amber-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                        d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>
                <p class="text-lg font-medium text-gray-500">Bắt đầu tra cứu tài liệu</p>
                <p class="text-sm mt-1">Đặt câu hỏi về các tài liệu Phật giáo đã được tải lên hệ thống</p>
            </div>
            @endif

            @foreach($messages as $msg)
            <div class="flex {{ $msg['role'] === 'user' ? 'justify-end' : 'justify-start' }} gap-3">

                @if($msg['role'] === 'assistant')
                <div class="w-8 h-8 rounded-full bg-amber-100 flex items-center justify-center shrink-0 mt-1">
                    <svg class="w-4 h-4 text-amber-700" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M9 4.804A7.968 7.968 0 005.5 4c-1.255 0-2.443.29-3.5.804v10A7.969 7.969 0 015.5 14c1.669 0 3.218.51 4.5 1.385A7.962 7.962 0 0114.5 14c1.255 0 2.443.29 3.5.804v-10A7.968 7.968 0 0014.5 4c-1.255 0-2.443.29-3.5.804V12a1 1 0 11-2 0V4.804z"/>
                    </svg>
                </div>
                @endif

                <div class="max-w-2xl">
                    <div class="rounded-2xl px-4 py-3 text-sm leading-relaxed
                        {{ $msg['role'] === 'user'
                            ? 'bg-amber-700 text-white rounded-br-sm'
                            : 'bg-white text-gray-800 shadow-sm border border-gray-100 rounded-bl-sm' }}">
                        {!! nl2br(e($msg['content'])) !!}
                    </div>

                    @if(!empty($msg['sources']))
                    <div class="mt-2 space-y-1">
                        <p class="text-xs text-gray-400 font-medium ml-1">Nguồn tham khảo:</p>
                        @foreach($msg['sources'] as $source)
                            @if(($source['type'] ?? 'document') === 'monastic')
                            <div class="bg-emerald-50 border border-emerald-200 rounded-lg px-3 py-2 text-xs">
                                <div class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-medium text-emerald-900 truncate">
                                            {{ $source['name'] }}{{ $source['religious_name'] ? ' (' . $source['religious_name'] . ')' : '' }}
                                        </p>
                                        <p class="text-emerald-600 truncate">
                                            {{ collect([$source['rank'], $source['position'], $source['temple'], $source['province']])->filter()->implode(' — ') }}
                                        </p>
                                    </div>
                                    <span class="text-emerald-500 uppercase font-bold shrink-0">Tăng Ni</span>
                                </div>

                                @if(!empty($source['documents']))
                                <div class="mt-2 pt-2 border-t border-emerald-200 flex flex-wrap gap-1.5">
                                    @foreach($source['documents'] as $doc)
                                    <a href="{{ $doc['download_url'] }}" target="_blank"
                                        class="flex items-center gap-1 bg-white border border-emerald-300 rounded-md px-2 py-1 text-emerald-700 hover:bg-emerald-100 transition">
                                        <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                        </svg>
                                        <span class="truncate max-w-[10rem]">{{ $doc['title'] }}</span>
                                        <span class="uppercase font-bold text-emerald-400">{{ $doc['file_type'] }}</span>
                                    </a>
                                    @endforeach
                                </div>
                                @endif
                            </div>
                            @else
                            <a href="{{ $source['download_url'] }}" target="_blank"
                                class="flex items-center gap-2 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-xs hover:bg-amber-100 transition group">
                                <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                </svg>
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-amber-900 truncate">{{ $source['title'] }}</p>
                                    <p class="text-amber-600 truncate">
                                        {{ collect([$source['monastic'] ?? null, $source['temple'] ?? null, $source['province'] ?? null])->filter()->implode(' — ') }}
                                    </p>
                                </div>
                                <span class="text-amber-500 uppercase font-bold">{{ $source['file_type'] }}</span>
                                <svg class="w-3.5 h-3.5 text-amber-400 group-hover:text-amber-600 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                            </a>
                            @endif
                        @endforeach
                    </div>
                    @endif
                </div>

                @if($msg['role'] === 'user')
                <div class="w-8 h-8 rounded-full bg-amber-700 flex items-center justify-center shrink-0 mt-1 text-white text-xs font-bold">
                    B
                </div>
                @endif
            </div>
            @endforeach

            @if($loading)
            <div class="flex justify-start gap-3">
                <div class="w-8 h-8 rounded-full bg-amber-100 flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4 text-amber-700 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                </div>
                <div class="bg-white shadow-sm border border-gray-100 rounded-2xl rounded-bl-sm px-4 py-3">
                    <div class="flex gap-1 items-center h-5">
                        <span class="w-2 h-2 bg-amber-400 rounded-full animate-bounce" style="animation-delay:0ms"></span>
                        <span class="w-2 h-2 bg-amber-400 rounded-full animate-bounce" style="animation-delay:150ms"></span>
                        <span class="w-2 h-2 bg-amber-400 rounded-full animate-bounce" style="animation-delay:300ms"></span>
                    </div>
                </div>
            </div>
            @endif
        </div>

        {{-- Input --}}
        <div class="bg-white border-t px-6 py-4">
            <form wire:submit="send" class="flex gap-3">
                <input
                    wire:model="question"
                    type="text"
                    placeholder="Nhập câu hỏi về tài liệu Phật giáo..."
                    class="flex-1 rounded-xl border border-gray-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400 focus:border-transparent"
                    @if($loading) disabled @endif
                    autofocus
                />
                <button
                    type="submit"
                    class="bg-amber-700 hover:bg-amber-800 text-white rounded-xl px-5 py-3 text-sm font-medium transition disabled:opacity-50"
                    @if($loading) disabled @endif
                >
                    Gửi
                </button>
            </form>
            <p class="text-xs text-gray-400 mt-2 text-center">
                AI có thể mắc lỗi. Vui lòng kiểm tra lại tài liệu gốc để xác minh thông tin.
            </p>
        </div>
    </div>
</div>

<script>
    document.addEventListener('livewire:updated', () => {
        const el = document.getElementById('chat-messages');
        if (el) el.scrollTop = el.scrollHeight;
    });
</script>
