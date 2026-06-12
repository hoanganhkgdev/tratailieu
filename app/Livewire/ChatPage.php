<?php

namespace App\Livewire;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\RAGService;
use Livewire\Component;

class ChatPage extends Component
{
    public string $question = '';
    public array  $messages = [];
    public bool   $loading  = false;
    public ?int   $conversationId = null;

    public function mount(): void
    {
        $sessionId = session()->getId();

        $conversation = Conversation::firstOrCreate(
            ['session_id' => $sessionId],
            ['title' => 'Cuộc trò chuyện mới']
        );

        $this->conversationId = $conversation->id;

        $this->messages = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => [
                'role'    => $m->role,
                'content' => $m->content,
                'sources' => $m->sources ?? [],
            ])->toArray();
    }

    public function send(): void
    {
        if (empty(trim($this->question))) {
            return;
        }

        $question = trim($this->question);
        $this->question = '';
        $this->loading  = true;

        $this->messages[] = ['role' => 'user', 'content' => $question, 'sources' => []];

        Message::create([
            'conversation_id' => $this->conversationId,
            'role'            => 'user',
            'content'         => $question,
        ]);

        try {
            $rag    = app(RAGService::class);
            $result = $rag->answer($question);

            $this->messages[] = [
                'role'    => 'assistant',
                'content' => $result['answer'],
                'sources' => $result['sources'],
            ];

            Message::create([
                'conversation_id' => $this->conversationId,
                'role'            => 'assistant',
                'content'         => $result['answer'],
                'sources'         => $result['sources'],
            ]);
        } catch (\Throwable $e) {
            $this->messages[] = [
                'role'    => 'assistant',
                'content' => 'Xin lỗi, đã xảy ra lỗi khi xử lý câu hỏi của bạn. Vui lòng thử lại.',
                'sources' => [],
            ];
        }

        $this->loading = false;
    }

    public function newConversation(): void
    {
        $conversation = Conversation::create([
            'session_id' => session()->getId(),
            'title'      => 'Cuộc trò chuyện mới',
        ]);

        $this->conversationId = $conversation->id;
        $this->messages       = [];
    }

    public function render()
    {
        return view('livewire.chat-page')
            ->layout('layouts.chat');
    }
}
