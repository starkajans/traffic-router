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
                'temperature' => $temperature,
                'maxOutputTokens' => 4096,
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

        // Safety block?
        if (!empty($data['candidates'][0]['finishReason']) && $data['candidates'][0]['finishReason'] === 'SAFETY') {
            return '[Gemini güvenlik filtrelerine takıldı — başka bir şekilde sormayı dene]';
        }

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
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
