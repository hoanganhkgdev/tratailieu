<?php

namespace App\Services;

use App\Models\DocumentChunk;
use App\Models\Monastic;
use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Support\Collection;

class RAGService
{
    public function __construct(private EmbeddingService $embedding) {}

    public function answer(string $question, int $topK = 8): array
    {
        $questionEmbedding = $this->embedding->embed($question);

        $items = $this->scoreDocumentChunks($questionEmbedding)
            ->concat($this->scoreMonastics($questionEmbedding));

        if ($items->isEmpty()) {
            return [
                'answer'  => 'Hiện chưa có dữ liệu nào trong hệ thống để tra cứu.',
                'sources' => [],
            ];
        }

        $scored = $items->sortByDesc('score')->take($topK);

        // Kèm theo tiêu đề/link tải (nếu là tài liệu) để AI có thể trả lời thẳng
        // khi người dùng hỏi xin link tải file — không chỉ dựa vào thẻ nguồn dưới UI.
        $context = $scored->map(function ($item) {
            $source = $item['source'];

            if ($source['type'] === 'document') {
                $meta = "[Tài liệu: \"{$source['title']}\" | Link tải xuống: {$source['download_url']}]";
            } else {
                $meta = "[Hồ sơ Tăng Ni: {$source['name']}" . ($source['religious_name'] ? " ({$source['religious_name']})" : '') . ']';
                foreach ($source['documents'] ?? [] as $doc) {
                    $meta .= "\n[Tài liệu đính kèm hồ sơ: \"{$doc['title']}\" | Link tải xuống: {$doc['download_url']}]";
                }
            }

            return $meta . "\n" . $item['content'];
        })->implode("\n\n---\n\n");

        // Chỉ lấy nguồn thực sự liên quan: điểm số phải gần với điểm cao nhất.
        // Khi câu hỏi nhắm vào 1 chùa/1 người cụ thể, mục đó sẽ vượt trội hẳn so với
        // phần còn lại; khi câu hỏi chung chung, nhiều mục sẽ có điểm gần nhau và đều
        // được giữ lại.
        $maxScore       = $scored->max('score') ?? 0;
        $relevanceGap   = 0.06;
        $relevantScored = $scored->filter(fn ($item) => $item['score'] >= $maxScore - $relevanceGap);

        $prompt = <<<PROMPT
Bạn là trợ lý tra cứu thông tin Phật giáo, bao gồm cả tài liệu về chùa/tự viện và hồ sơ Tăng Ni.
Hãy trả lời câu hỏi dựa trên thông tin được cung cấp dưới đây.
Nếu không tìm thấy thông tin liên quan, hãy nói rõ là không tìm thấy.
Nếu người dùng hỏi xin link/đường dẫn tải tài liệu, hãy lấy đúng "Link tải xuống" được ghi kèm
trong phần tham khảo (mục [Tài liệu: ...]) và đưa thẳng vào câu trả lời.
Trả lời bằng tiếng Việt, rõ ràng và súc tích.

=== THÔNG TIN THAM KHẢO ===
{$context}

=== CÂU HỎI ===
{$question}

=== TRẢ LỜI ===
PROMPT;

        $response = OpenAI::chat()->create([
            'model'    => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);
        $answer = $response->choices[0]->message->content;

        // Tài liệu đã hiện sẵn (đính kèm) bên trong thẻ hồ sơ Tăng Ni thì không cần
        // hiện lại lần nữa thành thẻ "tài liệu" độc lập bên dưới — tránh trùng lặp.
        $attachedDocumentIds = $relevantScored
            ->filter(fn ($item) => $item['source']['type'] === 'monastic')
            ->flatMap(fn ($item) => collect($item['source']['documents'] ?? [])->pluck('id'))
            ->unique();

        $sources = $relevantScored
            ->reject(fn ($item) => $item['source']['type'] === 'document'
                && $attachedDocumentIds->contains($item['source']['document_id']))
            ->map(fn ($item) => $item['source'])
            ->unique('key')
            ->values()
            ->toArray();

        return compact('answer', 'sources');
    }

    private function scoreDocumentChunks(array $questionEmbedding): Collection
    {
        return DocumentChunk::with(['document.temple.province', 'document.monastic.temple.province', 'document.monastic.province'])
            ->whereHas('document', fn ($q) => $q->where('status', 'ready'))
            ->get()
            ->map(function ($chunk) use ($questionEmbedding) {
                $document = $chunk->document;
                $monastic = $document->monastic;
                $temple   = $document->temple ?? $monastic?->temple;
                $score    = $this->embedding->cosineSimilarity($questionEmbedding, $chunk->embedding ?? []);

                return [
                    'score'   => $score,
                    'content' => $chunk->content,
                    'source'  => [
                        'key'          => 'document_' . $document->id,
                        'type'         => 'document',
                        'document_id'  => $document->id,
                        'title'        => $document->title,
                        'temple'       => $temple?->name,
                        'monastic'     => $monastic?->full_name,
                        'province'     => $temple?->province?->name ?? $monastic?->province?->name,
                        'file_type'    => $document->file_type,
                        'download_url' => $document->download_url,
                        'score'        => round($score, 3),
                    ],
                ];
            });
    }

    private function scoreMonastics(array $questionEmbedding): Collection
    {
        return Monastic::with(['temple.province', 'province', 'documents' => fn ($q) => $q->where('status', 'ready')])
            ->whereNotNull('embedding')
            ->get()
            ->map(function (Monastic $monastic) use ($questionEmbedding) {
                $score = $this->embedding->cosineSimilarity($questionEmbedding, $monastic->embedding ?? []);

                return [
                    'score'   => $score,
                    'content' => $monastic->toSearchableText(),
                    'source'  => [
                        'key'            => 'monastic_' . $monastic->id,
                        'type'           => 'monastic',
                        'monastic_id'    => $monastic->id,
                        'name'           => $monastic->full_name,
                        'religious_name' => $monastic->religious_name,
                        'rank'           => Monastic::rankLabel($monastic->gender, $monastic->rank),
                        'position'       => $monastic->current_position,
                        'temple'         => $monastic->temple?->name,
                        'province'       => $monastic->province?->name ?? $monastic->temple?->province?->name,
                        'score'          => round($score, 3),
                        // Tài liệu đính kèm hồ sơ (quyết định bổ nhiệm, văn bằng...) — cho phép
                        // người hỏi tải về xem trực tiếp ngay từ kết quả tra cứu.
                        'documents'      => $monastic->documents->map(fn ($doc) => [
                            'id'           => $doc->id,
                            'title'        => $doc->title,
                            'file_type'    => $doc->file_type,
                            'download_url' => $doc->download_url,
                        ])->values()->toArray(),
                    ],
                ];
            });
    }
}
