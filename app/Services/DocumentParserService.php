<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class DocumentParserService
{
    public function extractText(string $filePath, string $fileType): string
    {
        // R2 (driver s3) không có filesystem local nên path() không dùng được —
        // tải nội dung về 1 file tạm rồi parse, xong xoá ngay.
        $tmpPath = tempnam(sys_get_temp_dir(), 'temple_doc_').'.'.$fileType;
        file_put_contents($tmpPath, Storage::disk('public')->get($filePath));

        try {
            return match ($fileType) {
                'pdf'   => $this->extractFromPdf($tmpPath),
                'docx'  => $this->extractFromDocx($tmpPath),
                default => throw new \InvalidArgumentException("Không hỗ trợ định dạng: {$fileType}"),
            };
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * Dùng riêng cho phiếu tăng ni (có nhiều field checkbox ☒/☐) — PhpWord không
     * duyệt vào được các checkbox nằm trong <w:sdt> (Content Control của Word), làm
     * mất hẳn ký tự ☒/☐ khi trích bằng extractText() thường (đã kiểm chứng thực tế:
     * "☒ Chức sắc ☒ Chức việc ☐ Nhà tu hành" bị rút gọn còn "Chức sắc Chức việc Nhà
     * tu hành", AI không còn cách nào biết ô nào được tick). Đọc thẳng raw XML, lấy
     * nội dung MỌI thẻ <w:t> theo đúng thứ tự xuất hiện — cách này đi qua path khác
     * hẳn PhpWord nên không bỏ sót nội dung trong <w:sdt>. PDF không bị vấn đề này
     * (đã kiểm chứng: smalot/pdfparser giữ nguyên ☒/☐ khi extractText() thường) nên
     * chỉ cần override riêng cho docx.
     */
    public function extractTextPreservingCheckboxes(string $filePath, string $fileType): string
    {
        if ($fileType !== 'docx') {
            return $this->extractText($filePath, $fileType);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'monastic_doc_').'.docx';
        file_put_contents($tmpPath, Storage::disk('public')->get($filePath));

        try {
            return $this->extractDocxTextViaRawXml($tmpPath);
        } finally {
            @unlink($tmpPath);
        }
    }

    private function extractDocxTextViaRawXml(string $absolutePath): string
    {
        $zip = new \ZipArchive();

        if ($zip->open($absolutePath) !== true) {
            throw new \RuntimeException('Không mở được file docx để đọc.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new \RuntimeException('File docx không có word/document.xml.');
        }

        // (?=[ >]) bắt buộc ký tự ngay sau "t" phải là dấu cách hoặc ">" — nếu không,
        // "<w:t" khớp nhầm luôn phần đầu của <w:tabs>, <w:tbl>, <w:tc>... (mọi thẻ XML
        // Word bắt đầu bằng "w:t"), khiến regex "ăn" luôn từ đó tới tận </w:t> THẬT sự
        // tiếp theo và nuốt nguyên đống XML thô ở giữa vào kết quả (đã tái hiện được
        // lỗi này thực tế — text lẫn cả "<w:tab .../></w:tabs>..." vào giữa câu).
        // (?![^>]*\/>) loại further các thẻ <w:t .../> tự đóng (rỗng, không có nội dung).
        preg_match_all('/<w:t(?=[ >])(?![^>]*\/>)[^>]*>(.*?)<\/w:t>/su', $xml, $matches);

        // KHÔNG chèn thêm khoảng trắng giữa các <w:t> — bản thân nội dung mỗi thẻ đã
        // giữ đúng khoảng trắng cần thiết (xml:space="preserve"); Word hay tách 1 câu
        // thành nhiều <w:t> do ranh giới định dạng (in đậm, gạch chân...), chèn thêm
        // dấu cách sẽ làm vỡ từ ở giữa (vd "Ch"+"ức sắc" → "Ch ức sắc" thay vì "Chức sắc").
        return html_entity_decode(implode('', $matches[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function extractFromPdf(string $absolutePath): string
    {
        $parser   = new PdfParser();
        $document = $parser->parseFile($absolutePath);

        return $document->getText();
    }

    private function extractFromDocx(string $absolutePath): string
    {
        $phpWord = WordIOFactory::load($absolutePath, 'Word2007');
        $text    = '';

        foreach ($phpWord->getSections() as $section) {
            $text .= $this->extractSectionText($section->getElements());
        }

        return $text;
    }

    private function extractSectionText(array $elements): string
    {
        $text = '';

        foreach ($elements as $element) {
            if (method_exists($element, 'getText')) {
                $value = $element->getText();
                $text .= is_string($value) ? $value.' ' : '';
            } elseif (method_exists($element, 'getRows')) {
                foreach ($element->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        $text .= $this->extractSectionText($cell->getElements())."\t";
                    }
                    $text .= "\n";
                }
            } elseif (method_exists($element, 'getElements')) {
                $text .= $this->extractSectionText($element->getElements());
            }
        }

        return $text;
    }
}
