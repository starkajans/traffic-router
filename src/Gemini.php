<?php
declare(strict_types=1);

namespace Trafic;

/**
 * Minimal Google Gemini API client.
 *
 * Usage:
 *   $g = new Gemini($apiKey, 'gemini-2.5-flash');
 *   $text = $g->chat([
 *       ['role' => 'user', 'content' => 'Selam'],
 *   ], 'You are a helpful assistant');
 */
final class Gemini
{
    private string $apiKey;
    private string $model;
    private int $timeout;

    public function __construct(string $apiKey, string $model = 'gemini-2.5-flash', int $timeout = 30)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = $timeout;
    }

    /**
     * Send a chat with optional system instruction. Returns the assistant's text.
     *
     * @param array<array{role:string,content:string}> $messages
     */
    public function chat(array $messages, ?string $systemInstruction = null, float $temperature = 0.7): string
    {
        $contents = [];
        foreach ($messages as $m) {
            $role = $m['role'] === 'user' ? 'user' : 'model';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => (string) $m['content']]],
            ];
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature'     => $temperature,
                'maxOutputTokens' => 8192,
                // Disable thinking on Gemini 2.5 family — thinking tokens eat into
                // the output budget and a complex system prompt can starve actual
                // response generation. We don't need step-by-step reasoning here.
                'thinkingConfig'  => ['thinkingBudget' => 0],
            ],
        ];
        if ($systemInstruction !== null && $systemInstruction !== '') {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]],
            ];
        }

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($this->model),
            rawurlencode($this->apiKey)
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Gemini bağlantı hatası: $err");
        }
        if ($code !== 200) {
            $body = json_decode((string) $response, true);
            $msg = $body['error']['message'] ?? (string) $response;
            throw new \RuntimeException("Gemini API hatası (HTTP $code): $msg");
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Gemini geçersiz JSON döndürdü');
        }

        // Prompt-level block (returns no candidates at all)
        if (empty($data['candidates'])) {
            $reason = $data['promptFeedback']['blockReason'] ?? 'unknown';
            error_log('[Gemini] No candidates. Full response: ' . substr((string) $response, 0, 2000));
            throw new \RuntimeException("Gemini cevap üretmedi (block reason: $reason). Model adını veya prompt'u kontrol et.");
        }

        $candidate = $data['candidates'][0];
        $finishReason = $candidate['finishReason'] ?? '';
        $text = $candidate['content']['parts'][0]['text'] ?? '';

        if ($text === '') {
            error_log('[Gemini] Empty text. finishReason=' . $finishReason . ' response=' . substr((string) $response, 0, 2000));
            switch ($finishReason) {
                case 'SAFETY':
                    return '[Gemini güvenlik filtrelerine takıldı — farklı bir ifade dene]';
                case 'MAX_TOKENS':
                    return '[Cevap çok uzundu, max token sınırına dayandı]';
                case 'RECITATION':
                    return '[Gemini telif/recitation filtresi engelledi]';
                case 'OTHER':
                    return '[Gemini "OTHER" sebebiyle cevap vermedi — modeli değiştirmeyi dene]';
                default:
                    return '[Gemini boş cevap döndü (finishReason: ' . ($finishReason ?: 'yok') . '). Settings\'ten farklı bir model dene.]';
            }
        }

        return (string) $text;
    }

    /** Cheap connectivity test — returns true if API key + model work. */
    public function testConnection(): array
    {
        try {
            $text = $this->chat([['role' => 'user', 'content' => 'Reply with the single word: OK']], null, 0.0);
            return ['ok' => true, 'message' => trim($text)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
