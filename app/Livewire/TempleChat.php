<?php

namespace App\Livewire;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\TempleChatService;
use App\Services\TempleSearchService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.chat')]
class TempleChat extends Component
{
    public ?int $conversationId = null;

    public string $question = '';

    public function mount(): void
    {
        $latest = Auth::user()->conversations()->latest('id')->first();

        if ($latest) {
            $this->conversationId = $latest->id;
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

        $temples = app(TempleSearchService::class)->search($question);
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
