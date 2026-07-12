<?php

namespace App\Livewire;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\MonasticChatService;
use App\Services\MonasticSearchService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Sao chép nguyên kiến trúc TempleChat — xem đó để hiểu lý do #[Url]/mount()/loadConversations()
 * — chỉ khác: lọc theo type='monastic' để 2 luồng không lẫn lịch sử chat của nhau
 * (cùng dùng chung bảng conversations/messages, xem migration add_type_to_conversations_table).
 */
#[Layout('layouts.chat', ['title' => 'Tra cứu tăng ni'])]
class MonasticChat extends Component
{
    #[Url(as: 'c', history: true)]
    public ?int $conversationId = null;

    public string $question = '';

    public function mount(): void
    {
        if ($this->conversationId && ! Auth::user()->conversations()->where('type', 'monastic')->where('id', $this->conversationId)->exists()) {
            $this->conversationId = null;
        }
    }

    /** @return \Illuminate\Support\Collection<int, Conversation> */
    private function loadConversations()
    {
        return Auth::user()->conversations()->where('type', 'monastic')->latest('id')->get();
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
        $conversation = Auth::user()->conversations()->where('type', 'monastic')->findOrFail($conversationId);
        $this->conversationId = $conversation->id;
        $this->question = '';
    }

    public function deleteConversation(int $conversationId): void
    {
        $conversation = Auth::user()->conversations()->where('type', 'monastic')->findOrFail($conversationId);
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
                'type'  => 'monastic',
            ]);
            $this->conversationId = $conversation->id;
        }

        Message::create([
            'conversation_id' => $this->conversationId,
            'role'            => 'user',
            'content'         => $question,
        ]);

        $this->question = '';

        $profiles = app(MonasticSearchService::class)->search($question, limit: 10);
        $answer = app(MonasticChatService::class)->ask($question, $profiles);

        Message::create([
            'conversation_id' => $this->conversationId,
            'role'            => 'assistant',
            'content'         => $answer,
            'monastics'       => $profiles->map(fn ($p) => [
                'full_name'    => $p->full_name,
                'temple'       => $p->temple?->name,
                'province'     => $p->province?->name,
                'download_url' => $p->document?->download_url,
            ])->all(),
        ]);
    }

    public function render()
    {
        return view('livewire.monastic-chat', [
            'conversations' => $this->loadConversations(),
            'messages'      => $this->loadMessages(),
        ]);
    }
}
