<?php

namespace App\Livewire;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\TempleChatService;
use App\Services\TempleSearchService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.chat')]
class TempleChat extends Component
{
    // Đồng bộ vào URL (?c=ID) thay vì tự chọn "cuộc trò chuyện gần nhất" mỗi lần
    // mount — nếu không, bấm "Trò chuyện mới" rồi F5 sẽ lại nhảy về cuộc cũ vì
    // component mount lại từ đầu và không còn nhớ gì đã chọn "mới".
    #[Url(as: 'c', history: true)]
    public ?int $conversationId = null;

    public string $question = '';

    public function mount(): void
    {
        // conversationId có thể tới từ URL — xác thực nó thật sự thuộc user này,
        // không thì bỏ qua (coi như trò chuyện mới) thay vì lỗi hoặc lộ dữ liệu
        // người khác.
        if ($this->conversationId && ! Auth::user()->conversations()->where('id', $this->conversationId)->exists()) {
            $this->conversationId = null;
        }
    }

    /** @return \Illuminate\Support\Collection<int, Conversation> */
    private function loadConversations()
    {
        return Auth::user()->conversations()->latest('id')->get();
    }

    /** @return \Illuminate\Support\Collection<int, Message> */
    private function loadMessages()
    {
        if (! $this->conversationId) {
            return collect();
        }

        return Message::where('conversation_id', $this->conversationId)->orderBy('created_at')->get();
    }

    public function newChat(): void
    {
        $this->conversationId = null;
        $this->question = '';
    }

    public function selectConversation(int $conversationId): void
    {
        $conversation = Auth::user()->conversations()->findOrFail($conversationId);
        $this->conversationId = $conversation->id;
        $this->question = '';
    }

    public function deleteConversation(int $conversationId): void
    {
        $conversation = Auth::user()->conversations()->findOrFail($conversationId);
        $conversation->delete();

        if ($this->conversationId === $conversationId) {
            $this->newChat();
        }
    }

    public function ask(): void
    {
        $question = trim($this->question);

        if ($question === '') {
            return;
        }

        if (! $this->conversationId) {
            $conversation = Auth::user()->conversations()->create([
                'title' => Str::limit($question, 60),
            ]);
            $this->conversationId = $conversation->id;
        }

        Message::create([
            'conversation_id' => $this->conversationId,
            'role'            => 'user',
            'content'         => $question,
        ]);

        $this->question = '';

        // Limit 10 (thay vì mặc định 5) — chế độ liệt kê danh sách cần đủ số lượng để
        // hữu ích khi 1 tên chùa trùng ở nhiều tỉnh (vd "Chùa Phật Quang" có ở hơn
        // chục tỉnh), giúp người dùng thấy đủ để chọn tỉnh cần gõ thêm.
        $temples = app(TempleSearchService::class)->search($question, limit: 10);
        $answer = app(TempleChatService::class)->ask($question, $temples);

        Message::create([
            'conversation_id' => $this->conversationId,
            'role'            => 'assistant',
            'content'         => $answer,
            'temples'         => $temples->map(fn ($t) => [
                'name'         => $t->name,
                'code'         => $t->code,
                'province'     => $t->province?->name,
                'download_url' => $t->latestDocument?->download_url,
            ])->all(),
        ]);
    }

    public function render()
    {
        return view('livewire.temple-chat', [
            'conversations' => $this->loadConversations(),
            'messages'      => $this->loadMessages(),
        ]);
    }
}
