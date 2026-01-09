<?php

declare(strict_types=1);
// services/GroqService.php
namespace App\Services;

use App\Modelos\IAService;
use App\Modelos\ChatMessage;
use App\Modelos\Role;

use RuntimeException;
use InvalidArgumentException;

final class GroqService implements IAService
{
    private string $apiKey;
    private string $baseUrl;

    //default parameters
    private string $model;
    private float $temperature;
    private int $maxTokens;
    private float $topP;
    private mixed $stop;

    public function __construct(
        ?string $apiKey = null,
        string $baseUrl = 'https://api.groq.com/openai/v1',
        ?string $model = null,
        float $temperature = 0.6,
        int $maxTokens = 4096,
        float $topP = 1.0,
        mixed $stop = null
    ) {

        $apiKey = $apiKey ?? ($_ENV['GROQ_API_KEY'] ?? '');
        $apiKey = is_string($apiKey) ? $apiKey : '';

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');

        // por defecto: si no pasas modelo, lo cogemos del env o uno fijo
        $this->model = $model ?? ($_ENV['GROQ_MODEL'] ?? 'moonshotai/kimi-k2-instruct-0905');

        $this->temperature = $temperature;
        $this->maxTokens = $maxTokens;
        $this->topP = $topP;
        $this->stop = $stop;
    }

    private function ensureApiKey(): void
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('La clave API de Groq no está configurada.');
        }
    }

    public function name(): string
    {
        return 'groq';
    }

    /**
     * @param ChatMessage[] $messages
     * @param callable(string): void $onDelta
     */
    public function stream(array $messages, callable $onDelta): void
    {
        $this->ensureApiKey();
        $this->createStream(
            $messages,
            $this->model,
            $onDelta,
            $this->temperature,
            $this->maxTokens,
            $this->topP,
            $this->stop
        );
    }


    /**
     * Llamada NO streaming. Devuelve el texto final.
     * 
     * @param array<int, ChatMessage|array{role: string|Role, content: string}> $messages

     */


    public function create(array $messages, string $model, float $temperature = 0.6, int $maxTokens = 4096, float $topP = 1.0, $stop = null): string
    {
        $messages = $this->normalizeMessages($messages);
        $data = $this->request([
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_completion_tokens' => $maxTokens,
            'top_p' => $topP,
            'stream' => false,
            'stop' => $stop,
        ]);

        return (string) ($data['choices'][0]['message']['content'] ?? '');
    }

    /**
     * Streaming estilo "for await" pero con callback.
     * $onDelta recibe cada trozo de texto.     
     * @param array<int, ChatMessage|array{role: string|Role, content: string}> $messages
     */
    public function createStream(
        array $messages,
        string $model,
        callable $onDelta,
        float $temperature = 0.6,
        int $maxTokens = 4096,
        float $topP = 1.0,
        $stop = null
    ): void {
        $url = $this->baseUrl . '/chat/completions';
        $messages = $this->normalizeMessages($messages);


        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_completion_tokens' => $maxTokens,
            'top_p' => $topP,
            'stream' => true,
            'stop' => $stop,
        ];

        $ch = curl_init($url);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        $buffer = '';

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => false, // importante para streaming
            CURLOPT_TIMEOUT => 0,             // sin límite (stream)
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buffer, $onDelta): int {
                $buffer .= $chunk;

                // Procesa por líneas SSE
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);

                    if ($line === '' || !str_starts_with($line, 'data:')) {
                        continue;
                    }

                    $data = trim(substr($line, 5));
                    if ($data === '[DONE]') {
                        continue;
                    }

                    $json = json_decode($data, true);
                    if (!is_array($json)) {
                        continue;
                    }

                    $delta = $json['choices'][0]['delta']['content'] ?? '';
                    if ($delta !== '') {
                        $onDelta($delta);
                        continue;
                    }
                }

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        if ($ok === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL streaming error: {$err}");
        }

        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http >= 400) {
            throw new RuntimeException("HTTP error en streaming: {$http}");
        }
    }

    private function normalizeMessages(array $messages): array
    {
        $normalized = [];
        foreach ($messages as $msg) {
            if ($msg instanceof ChatMessage) {
                $role = $msg->role->value;
                $content = $msg->content;
            } elseif (is_array($msg) && isset($msg['role'], $msg['content'])) {
                $role = $msg['role'] instanceof Role ? $msg['role']->value : (string) $msg['role'];
                $content = (string) $msg['content'];
            } else {
                throw new InvalidArgumentException('Mensaje inválido en el array de mensajes.');
            }
            $normalized[] = [
                'role' => $role,
                'content' => $content,
            ];
        }
        return $normalized;
    }

    private function request(array $payload): array
    {
        $url = $this->baseUrl . '/chat/completions';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL error: {$err}");
        }

        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Respuesta no JSON: ' . substr((string) $raw, 0, 300));
        }
        if ($http >= 400) {
            $msg = $data['error']['message'] ?? "HTTP {$http}";
            throw new RuntimeException("API error: {$msg}");
        }

        return $data;
    }
}
