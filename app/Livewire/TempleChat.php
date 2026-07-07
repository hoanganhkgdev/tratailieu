<?php

namespace App\Livewire;

use App\Services\TempleChatService;
use App\Services\TempleSearchService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.chat')]
class TempleChat extends Component
{
    /** @var array<int, array{role: string, content: string, temples?: array}> */
    public array $messages = [];

    public string $question = '';

    public function ask(): void
    {
        $question = trim($this->question);

        if ($question === '') {
            return;
        }

        $this->messages[] = ['role' => 'user', 'content' => $question];
        $this->question = '';

        $temples = app(TempleSearchService::class)->search($question);
        $answer = app(TempleChatService::class)->ask($question, $temples);

        $this->messages[] = [
            'role'    => 'assistant',
            'content' => $answer,
            'temples' => $temples->map(fn ($t) => [
                'name'         => $t->name,
                'code'         => $t->code,
                'province'     => $t->province?->name,
                'download_url' => $t->latestDocument?->download_url,
            ])->all(),
        ];
    }

    public function clearHistory(): void
    {
        $this->messages = [];
    }

    public function render()
    {
        return view('livewire.temple-chat');
    }
}
