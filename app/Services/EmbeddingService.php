<?php

namespace App\Services;

use Gemini\Laravel\Facades\Gemini;

class EmbeddingService
{
    public function embed(string $text): array
    {
        $response = Gemini::embeddingModel('gemini-embedding-001')
            ->embedContent($text);

        return $response->embedding->values;
    }

    public function embedBatch(array $texts): array
    {
        $embeddings = [];
        foreach ($texts as $text) {
            $embeddings[] = $this->embed($text);
        }
        return $embeddings;
    }

    public function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $val) {
            $dot   += $val * ($b[$i] ?? 0);
            $normA += $val * $val;
            $normB += ($b[$i] ?? 0) * ($b[$i] ?? 0);
        }

        $denom = sqrt($normA) * sqrt($normB);
        return $denom > 0 ? $dot / $denom : 0.0;
    }
}
