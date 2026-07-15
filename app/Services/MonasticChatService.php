<?php

namespace App\Services;

use App\Models\MonasticProfile;
use Illuminate\Support\Collection;

/**
 * Thuần PHP, không qua AI — cùng lý do với TempleChatService: dữ liệu đã được
 * MonasticSearchService lọc chính xác sẵn, chỉ cần đổ vào khuôn markdown cố định.
 */
class MonasticChatService
{
    private const CLASSIFICATION_LABELS = [
        'chuc_sac'    => 'Chức sắc',
        'chuc_viec'   => 'Chức việc',
        'nha_tu_hanh' => 'Nhà tu hành',
    ];

    public function ask(string $question, Collection $profiles): string
    {
        if ($profiles->isEmpty()) {
            return 'Không tìm thấy tăng ni nào khớp với câu hỏi của bạn. Thử lại với họ tên, pháp danh, số điện thoại hoặc số CCCD.';
        }

        if ($profiles->count() > 1) {
            return $this->formatList($profiles);
        }

        return $this->formatDetail($profiles->first());
    }

    /**
     * Định dạng mỗi dòng PHẢI khớp đúng regex parse ngược ở
     * MonasticSearchService::searchFromListLine() — sửa 1 bên thì phải sửa bên kia.
     */
    private function formatList(Collection $profiles): string
    {
        $lines = $profiles->values()->map(function (MonasticProfile $profile, int $index) {
            $number = $index + 1;

            $line = "{$number}. **{$profile->full_name}**";

            if (filled($profile->religious_name)) {
                $line .= " ({$profile->religious_name})";
            }

            $line .= ' — Tỉnh: '.($profile->province?->name ?? 'Chưa rõ');

            return $line;
        });

        return "Tìm thấy {$profiles->count()} tăng ni phù hợp:\n\n"
            .$lines->implode("\n")
            ."\n\nBạn gõ rõ hơn họ tên/pháp danh (kèm tên chùa hoặc tỉnh nếu trùng tên) để xem chi tiết đầy đủ.";
    }

    private function formatDetail(MonasticProfile $profile): string
    {
        $lines = [];
        $lines[] = "### {$profile->full_name}".(filled($profile->religious_name) ? " ({$profile->religious_name})" : '');

        if (filled($profile->province?->name)) {
            $lines[] = "- Tỉnh: {$profile->province->name}";
        }

        if (filled($profile->birth_date)) {
            $lines[] = '- Ngày sinh: '.$profile->birth_date->format('d/m/Y');
        }

        if (filled($profile->gender)) {
            $lines[] = "- Giới tính: {$profile->gender}";
        }

        if (filled($profile->classification)) {
            $labels = collect($profile->classification)->map(fn ($v) => self::CLASSIFICATION_LABELS[$v] ?? $v)->implode(', ');
            $lines[] = "- Phân loại: {$labels}";
        }

        if (filled($profile->current_position)) {
            $lines[] = "- Chức vụ/Phẩm vị hiện tại: {$profile->current_position}";
        }

        if (filled($profile->sect)) {
            $lines[] = "- Hệ phái/Dòng tu: {$profile->sect}";
        }

        if (filled($profile->religious_education_level)) {
            $lines[] = "- Trình độ tu học: {$profile->religious_education_level}";
        }

        if (filled($profile->phone)) {
            $lines[] = "- Điện thoại: {$profile->phone}";
        }

        if (filled($profile->status)) {
            $lines[] = "- Tình trạng hiện tại: {$profile->status}";
        }

        $downloadUrl = $profile->document?->download_url;

        if ($downloadUrl) {
            $lines[] = '';
            $lines[] = "**Tải tài liệu**: [Tải file gốc]({$downloadUrl})";
        }

        return implode("\n", $lines);
    }
}
