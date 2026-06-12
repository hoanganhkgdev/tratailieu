<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;

class DocumentParserService
{
    public function extractText(string $filePath, string $fileType): string
    {
        $fullPath = $this->resolvePath($filePath);

        return match ($fileType) {
            'pdf'  => $this->parsePdf($fullPath),
            'docx' => $this->parseDocx($fullPath),
            default => throw new \InvalidArgumentException("Unsupported file type: {$fileType}"),
        };
    }

    public function resolvePath(string $filePath): string
    {
        // Nếu là absolute path thì dùng thẳng
        if (str_starts_with($filePath, '/') && file_exists($filePath)) {
            return $filePath;
        }

        // Thử từng disk theo thứ tự ưu tiên
        foreach (['public', 'local'] as $disk) {
            $path = Storage::disk($disk)->path($filePath);
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new \RuntimeException("File không tồn tại: {$filePath}");
    }

    private function parsePdf(string $path): string
    {
        $parser = new Parser();

        // parseContent đọc trực tiếp từ binary, không cần extension
        $pdf = $parser->parseContent(file_get_contents($path));

        return $pdf->getText();
    }

    private function parseDocx(string $path): string
    {
        // PHPWord detect format từ extension — nếu file không có .docx thì copy sang file tạm
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'docx') {
            $tmp = tempnam(sys_get_temp_dir(), 'phpword_') . '.docx';
            copy($path, $tmp);
            $phpWord = IOFactory::load($tmp, 'Word2007');
            @unlink($tmp);
        } else {
            $phpWord = IOFactory::load($path, 'Word2007');
        }

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
