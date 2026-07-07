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
