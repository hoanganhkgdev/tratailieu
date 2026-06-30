<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;

class DocumentParserService
{
    public function extractText(string $filePath, string $fileType): string
    {
        if (! Storage::disk('public')->exists($filePath)) {
            throw new \RuntimeException("File không tồn tại: {$filePath}");
        }

        $content = Storage::disk('public')->get($filePath);

        return match ($fileType) {
            'pdf'  => $this->parsePdf($content),
            'docx' => $this->parseDocx($content),
            default => throw new \InvalidArgumentException("Unsupported file type: {$fileType}"),
        };
    }

    private function parsePdf(string $content): string
    {
        $parser = new Parser();
        $pdf = $parser->parseContent($content);

        return $pdf->getText();
    }

    private function parseDocx(string $content): string
    {
        // PHPWord cần 1 file thật trên đĩa để đọc, nên ghi nội dung ra file tạm trước
        $tmp = tempnam(sys_get_temp_dir(), 'phpword_') . '.docx';
        file_put_contents($tmp, $content);
        $phpWord = IOFactory::load($tmp, 'Word2007');
        @unlink($tmp);

        $text    = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text .= $this->extractElementText($element);
            }
        }

        return $text;
    }

    private function extractElementText(mixed $element): string
    {
        $text = '';

        if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
            foreach ($element->getElements() as $child) {
                if ($child instanceof \PhpOffice\PhpWord\Element\Text) {
                    $text .= $child->getText() . ' ';
                }
            }
            $text .= "\n";
        } elseif ($element instanceof \PhpOffice\PhpWord\Element\Text) {
            $text .= $element->getText() . "\n";
        } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
            foreach ($element->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    foreach ($cell->getElements() as $cellElement) {
                        $text .= $this->extractElementText($cellElement);
                    }
                    $text .= ' | ';
                }
                $text .= "\n";
            }
        }

        return $text;
    }

    public function splitIntoChunks(string $text, int $chunkSize = 800, int $overlap = 100): array
    {
        $text   = preg_replace('/\s+/', ' ', trim($text));
        $words  = explode(' ', $text);
        $chunks = [];
        $i      = 0;

        while ($i < count($words)) {
            $chunk = implode(' ', array_slice($words, $i, $chunkSize));
            if (trim($chunk) !== '') {
                $chunks[] = $chunk;
            }
            $i += $chunkSize - $overlap;
        }

        return $chunks;
    }
}
