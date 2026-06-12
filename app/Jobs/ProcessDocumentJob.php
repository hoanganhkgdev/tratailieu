<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\DocumentParserService;
use App\Services\EmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ProcessDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries   = 2;

    public function __construct(public Document $document) {}

    public function handle(DocumentParserService $parser, EmbeddingService $embedding): void
    {
        $this->document->update(['status' => 'processing']);

        try {
            $fullPath = $parser->resolvePath($this->document->file_path);
            $this->document->update(['file_size' => filesize($fullPath) ?: 0]);

            $text = $parser->extractText($this->document->file_path, $this->document->file_type);

            if (empty(trim($text))) {
                throw new \RuntimeException('Không đọc được nội dung từ file.');
            }

            $chunks = $parser->splitIntoChunks($text);

            $this->document->chunks()->delete();

            foreach ($chunks as $index => $chunk) {
                $vector = $embedding->embed($chunk);

                DocumentChunk::create([
                    'document_id' => $this->document->id,
                    'chunk_index' => $index,
                    'content'     => $chunk,
                    'embedding'   => $vector,
                ]);
            }

            $this->document->update([
                'status'       => 'ready',
                'processed_at' => now(),
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            $this->document->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
