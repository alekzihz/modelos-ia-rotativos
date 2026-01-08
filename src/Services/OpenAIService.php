<?php

declare(strict_types=1);

namespace App\Services;

use App\Modelos\IAService;
use App\Modelos\ChatMessage;
use App\Modelos\Role;
use App\Excepciones\AIServiceException;

use RuntimeException;
use InvalidArgumentException;

final class OpenAIService implements IAService
{
    private string $apiKey;
    private string $baseUrl;

    // default parameters
    private string $model;
    private float $temperature;
    private int $maxOutputTokens;
    private float $topP;
    private bool $store;

    public function __construct(
        ?string $apiKey = null,
        string $baseUrl = 'https://api.openai.com/v1',
        ?string $model = null,
        float $temperature = 1.0,
        int $maxOutputTokens = 4096,
        float $topP = 1.0,
        bool $store = false
    ) {
        $apiKey = $apiKey ?? ($_ENV['OPENAI_API_KEY'] ?? '');
        $apiKey = is_string($apiKey) ? $apiKey : '';

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');


        // Si no pasas modelo, coge env o un default razonable

        $this->model = $model ?? ($_ENV['OPENAI_MODEL'] ?? 'gpt-3.5-turbo');
        //$this->model = $model ?? ($_ENV['OPENAI_MODEL'] ?? 'gpt-4.1-mini4');
        //$this->model = $model ?? ($_ENV['OPENAI_MODEL'] ?? 'gpt-5.2');

        $this->temperature = $temperature;
        $this->maxOutputTokens = $maxOutputTokens;
        $this->topP = $topP;
        $this->store = $store;
    }

    private function ensureApiKey(): void
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Falta OPENAI_API_KEY.');
        }
    }

    public function name(): string
    {
        return 'openai';
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
            $this->maxOutputTokens,
            $this->topP,
            $this->store
        );
    }

    /**
     * Streaming para Responses API.
     *
     * @param array<int, ChatMessage|array{role: string|Role, content: string}> $messages
     */
    public function createStream(
        array $messages,
        string $model,
        callable $onDelta,
        float $temperature = 1.0,
        int $maxOutputTokens = 4096,
        float $topP = 1.0,
        bool $store = false
    ): void {
        $url = $this->baseUrl . '/responses';

        // Responses API usa "input" en vez de "messages"
        $input = $this->normalizeMessages($messages);

        $payload = [
            'model' => $model,
            'input' => $input,
            'temperature' => $temperature,
            'max_output_tokens' => $maxOutputTokens,
            'top_p' => $topP,
            'stream' => true,
            'store' => $store,
        ];

        $ch = curl_init($url);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        $buffer = '';
        $errorBody = '';
        $streamFailed = false;
        $streamJson = null;


        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => false, // streaming
            CURLOPT_TIMEOUT => 0,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buffer, &$errorBody, $onDelta, &$streamFailed, &$streamErrorJson): int {
                $buffer .= $chunk;

                // Parseamos por líneas, y procesamos SOLO las que empiezan con "data:"
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);

                    if ($line === '' || !str_starts_with($line, 'data:')) {
                        continue;
                    }

                    if (!str_contains($chunk, 'data:')) {
                        if (strlen($errorBody) < 16000) {
                            $errorBody .= $chunk;
                        }
                    }

                    $data = trim(substr($line, 5));
                    //print_r($data);

                    // Por si llegara algo estilo [DONE]
                    if ($data === '[DONE]') {
                        continue;
                    }

                    $json = json_decode($data, true);
                    if (!is_array($json)) {
                        continue;
                    }



                    // En Responses API, los deltas llegan como:
                    // { "type": "response.output_text.delta", "delta": "..." }
                    $type = (string)($json['type'] ?? '');


                    if ($type === 'response.failed') {
                        $streamFailed = true;
                        $streamErrorJson = $json;
                        continue;
                    }


                    if ($type === 'response.output_text.delta') {
                        $delta = (string)($json['delta'] ?? '');
                        if ($delta !== '') {
                            $onDelta($delta);
                        }
                        continue;
                    }

                    // Si el modelo se niega, puedes mostrarlo también (opcional)
                    if ($type === 'response.refusal.delta') {
                        $delta = (string)($json['delta'] ?? '');
                        if ($delta !== '') {
                            $onDelta($delta);
                        }
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

        // Manejo de errores Responses API en streaming
        //─────────────────────────────
        // Si el stream ha fallado, el JSON de error está en $streamErrorJson
        //es la manera de capturarlo y lanzarlo como excepción adecuada 
        //para que no rompa el flujo general.

        /*
        Que sucedía? La petición streaming devolvía un error (por ejemplo, cuota insuficiente)
        pero como el flujo era streaming, no se capturaba bien el error HTTP,
        y el cliente no podía manejarlo correctamente.
             * ─────────────────────────────
             * ERRORES RESPONSES API
             * ─────────────────────────────
             * Ejemplo real:
             [type] => response.failed
                [response] => Array
                (
                    [id] => resp_03715dbcc690f53501695fca88c5bc81a1b693c273831ccb64
                    [object] => response
                    [created_at] => 1767885448
                    [status] => failed
                    [background] => 
                    [completed_at] => 
                    [error] => Array
                        (
                            [code] => insufficient_quota
                            [message] => You exceeded your current quota, please check your plan and billing details. For more information on this error, read the docs: https://platform.openai.com/docs/guides/error-codes/api-errors.
                    )

                     

        */
        if ($streamFailed && is_array($streamErrorJson)) {

            $err = $streamErrorJson ?? [];
            $response = $err['response'] ?? [];

            $message = (string)($response['error']['message'] ?? 'Error desconocido en streaming');
            $type    = isset($err['type']) ? (string)$err['type'] : null;
            $code    = isset($response['error']['code']) ? (string)$response['error']['code'] : null;

            throw new AIServiceException(
                $this->name(),
                $http > 0 ? $http : 500,
                $code,
                $type,
                $message
            );
        }


        if ($http >= 400) {
            throw new RuntimeException("HTTP error en streaming: {$http}");
        }
    }

    /**
     * Normaliza ChatMessage[] a array compatible con OpenAI Responses "input":
     * [
     *   { "role": "user", "content": "..." },
     *   ...
     * ]
     *
     * @param array<int, ChatMessage|array{role: string|Role, content: string}> $messages
     * @return array<int, array{role: string, content: string}>
     */
    private function normalizeMessages(array $messages): array
    {
        $normalized = [];

        foreach ($messages as $msg) {
            if ($msg instanceof ChatMessage) {
                $role = $msg->role->value;
                $content = $msg->content;
            } elseif (is_array($msg) && isset($msg['role'], $msg['content'])) {
                $role = $msg['role'] instanceof Role ? $msg['role']->value : (string)$msg['role'];
                $content = (string)$msg['content'];
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

    private function openaiListModels(string $apiKey): array
    {
        $ch = curl_init('https://api.openai.com/v1/models');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            throw new RuntimeException('Error cURL: ' . curl_error($ch));
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            throw new RuntimeException("OpenAI /models failed with HTTP $status: $response");
        }

        $json = json_decode($response, true);

        print_r($json);

        return $json['data'] ?? [];
    }
}
