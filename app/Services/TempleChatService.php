<?php

namespace App\Services;

use Illuminate\Support\Collection;
use OpenAI\Laravel\Facades\OpenAI;

class TempleChatService
{
    private const SYSTEM_PROMPT = <<<PROMPT
Bạn là trợ lý tra cứu thông tin tự viện Phật giáo Việt Nam cho quản trị viên nội bộ.
Chỉ trả lời dựa trên dữ liệu được cung cấp trong tin nhắn, KHÔNG suy đoán hay bổ sung
thông tin không có trong dữ liệu. Nếu dữ liệu không đủ để trả lời, nói rõ là không tìm
thấy thông tin đó. Trả lời ngắn gọn, đúng trọng tâm, bằng tiếng Việt.
PROMPT;

    public function ask(string $question, Collection $temples): string
    {
        if ($temples->isEmpty()) {
            return 'Không tìm thấy tự viện nào khớp với câu hỏi của bạn. Thử lại với tên chùa, địa chỉ, tên trụ trì hoặc số điện thoại.';
        }

        $context = $temples->map(function ($temple) {
            $monastics = $temple->monastics->map(
                fn ($m) => "  - {$m->full_name}".($m->religious_name ? " ({$m->religious_name})" : '')
                    .($m->rank ? ", {$m->rank}" : '')
                    .($m->position ? ", {$m->position}" : '')
                    .($m->birth_year ? ", sinh {$m->birth_year}" : '')
            )->implode("\n");

            return <<<TXT
Mã tự viện: {$temple->code}
Tên: {$temple->name}
Tỉnh/thành: {$temple->province?->name}
Địa chỉ: {$temple->address}
Trụ trì: {$temple->head_monk}
Điện thoại: {$temple->phone}
Danh sách chức sắc:
{$monastics}
TXT;
        })->implode("\n\n---\n\n");

        $response = OpenAI::chat()->create([
            'model'       => 'gpt-4o-mini',
            'temperature' => 0,
            'max_tokens'  => 800,
            'messages'    => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => "Dữ liệu tự viện tìm được:\n\n{$context}\n\nCâu hỏi: {$question}"],
            ],
        ]);

        return trim($response->choices[0]->message->content ?? 'Không nhận được câu trả lời từ AI.');
    }
}
