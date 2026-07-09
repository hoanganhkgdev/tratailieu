<?php

namespace App\Services;

use App\Models\Monastic;
use App\Models\Temple;
use Illuminate\Support\Collection;

class TempleChatService
{
    /**
     * Định dạng thuần PHP (không qua AI) — dữ liệu đã được TempleSearchService lọc
     * chính xác sẵn, AI chỉ từng dùng để đổ dữ liệu vào 1 khuôn markdown CỐ ĐỊNH bất
     * kể câu hỏi là gì, nên chuyển hẳn sang PHP: nhanh hơn, không tốn phí gọi AI, và
     * loại bỏ hẳn rủi ro AI không tuân đúng định dạng (từng gặp bug tên tự viện dính
     * vào danh sách chức sắc trước đó do AI tự ý đổi cấu trúc).
     *
     * Nhiều hơn 1 tự viện khớp (câu hỏi chưa đủ cụ thể, vd tên chùa trùng ở nhiều
     * tỉnh) → chỉ hiện danh sách gọn (tên chùa + tỉnh + trụ trì) để người dùng gõ rõ
     * hơn. Chỉ khi còn ĐÚNG 1 tự viện mới hiện đầy đủ chi tiết + chức sắc + link tải.
     */
    public function ask(string $question, Collection $temples): string
    {
        if ($temples->isEmpty()) {
            return 'Không tìm thấy tự viện nào khớp với câu hỏi của bạn. Thử lại với tên chùa, tên trụ trì, số điện thoại hoặc địa chỉ.';
        }

        if ($temples->count() > 1) {
            return $this->formatList($temples);
        }

        return $this->formatDetail($temples->first());
    }

    private function formatList(Collection $temples): string
    {
        $lines = $temples->values()->map(function (Temple $temple, int $index) {
            $number = $index + 1;
            $province = $temple->province?->name;

            $line = "{$number}. **{$temple->name}**".($province ? " ({$province})" : '');

            if (filled($temple->head_monk)) {
                $line .= " — Trụ trì: {$temple->head_monk}";
            }

            return $line;
        });

        return "Tìm thấy {$temples->count()} tự viện phù hợp:\n\n"
            .$lines->implode("\n")
            ."\n\nBạn gõ rõ hơn tên tự viện (kèm tên tỉnh/thành nếu trùng tên ở nhiều nơi) để xem chi tiết đầy đủ.";
    }

    private function formatDetail(Temple $temple): string
    {
        $province = $temple->province?->name;

        $lines = [];
        $lines[] = "### {$temple->name}".($province ? " ({$province})" : '');
        $lines[] = "- Mã tự viện: {$temple->code}";

        if (filled($temple->address)) {
            $lines[] = "- Địa chỉ: {$temple->address}";
        }

        if (filled($temple->head_monk)) {
            $lines[] = "- Trụ trì: {$temple->head_monk}";
        }

        if (filled($temple->phone)) {
            $lines[] = "- Điện thoại: {$temple->phone}";
        }

        if ($temple->monastics->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '**Các vị tu trong chùa**';

            array_push($lines, ...$temple->monastics->values()->map(
                fn (Monastic $m, int $i) => ($i + 1).'. '.$m->full_name
                    .($m->religious_name ? " ({$m->religious_name})" : '')
                    .($m->rank ? ", {$m->rank}" : '')
                    .($m->position ? ", {$m->position}" : '')
                    .($m->birth_year ? ", sinh {$m->birth_year}" : '')
            )->all());
        }

        $downloadUrl = $temple->latestDocument?->download_url;

        if ($downloadUrl) {
            $lines[] = '';
            $lines[] = "**Tải tài liệu**: [Tải file gốc]({$downloadUrl})";
        }

        return implode("\n", $lines);
    }
}
