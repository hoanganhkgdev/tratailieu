<?php

namespace App\Services;

use Illuminate\Support\Collection;
use OpenAI\Laravel\Facades\OpenAI;

class TempleChatService
{
    private const SYSTEM_PROMPT = <<<PROMPT
Bạn là trợ lý tra cứu thông tin tự viện Phật giáo Việt Nam cho quản trị viên nội bộ.
Chỉ trả lời dựa trên dữ liệu được cung cấp trong tin nhắn, KHÔNG suy đoán hay bổ sung
thông tin không có trong dữ liệu.

Nếu không có tự viện nào phù hợp với câu hỏi trong dữ liệu, chỉ cần nói ngắn gọn là
không tìm thấy, không cần theo định dạng bên dưới.

Nếu có, LUÔN trình bày mỗi tự viện liên quan theo đúng định dạng Markdown sau, giữ nguyên
cấu trúc dù câu hỏi chỉ hỏi 1 chi tiết cụ thể:

### {số}. {Tên tự viện} ({Tỉnh/thành})
- Mã tự viện: {mã}
- Địa chỉ: {địa chỉ}
- Trụ trì: {trụ trì}
- Điện thoại: {điện thoại}

**Các vị tu trong chùa**
1. {Họ và tên} ({Pháp danh}), {Giáo phẩm/Giới phẩm}, {Chức việc}, sinh {năm sinh}

**Tải tài liệu**: [Tải file gốc]({link tải})

---

Quy tắc BẮT BUỘC:
- Tên tự viện PHẢI là heading "### {số}." (3 dấu #), TUYỆT ĐỐI không viết thành mục trong
  danh sách số — nếu không, khi 2 tự viện trùng tên hoặc danh sách chức sắc dài, trình
  duyệt sẽ nối nhầm số thứ tự tự viện tiếp theo vào cuối danh sách chức sắc trước đó
  (ví dụ chùa thứ 2 hiện thành mục "7." thay vì heading riêng).
- Danh sách "Các vị tu trong chùa" LUÔN bắt đầu lại từ 1 cho mỗi tự viện, độc lập hoàn
  toàn với số thứ tự tự viện.
- Đặt "---" giữa các tự viện để tách rõ ràng khi có nhiều hơn 1 tự viện.
- Liệt kê ĐẦY ĐỦ tất cả các vị có trong dữ liệu, không rút gọn hay tóm tắt bớt.
- Field nào không có dữ liệu thì bỏ qua field đó, không ghi "không có" hay để trống.
- Nếu tự viện không có link tải, bỏ qua dòng "Tải tài liệu".
- Không thêm lời chào, giải thích, hay bình luận ngoài định dạng trên.
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

            $downloadUrl = $temple->latestDocument?->download_url;

            return <<<TXT
Mã tự viện: {$temple->code}
Tên: {$temple->name}
Tỉnh/thành: {$temple->province?->name}
Địa chỉ: {$temple->address}
Trụ trì: {$temple->head_monk}
Điện thoại: {$temple->phone}
Link tải file gốc: {$downloadUrl}
Danh sách chức sắc:
{$monastics}
TXT;
        })->implode("\n\n---\n\n");

        $response = OpenAI::chat()->create([
            'model'       => 'gpt-4o-mini',
            'temperature' => 0,
            // Tự viện lớn có thể tới 50-60 chức sắc, phải liệt kê đầy đủ từng vị
            // theo yêu cầu định dạng — 800 token cũ không đủ cho trường hợp này.
            'max_tokens'  => 4000,
            'messages'    => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => "Dữ liệu tự viện tìm được:\n\n{$context}\n\nCâu hỏi: {$question}"],
            ],
        ]);

        return trim($response->choices[0]->message->content ?? 'Không nhận được câu trả lời từ AI.');
    }
}
