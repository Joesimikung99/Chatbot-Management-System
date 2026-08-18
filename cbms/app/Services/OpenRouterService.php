<?php
/**
 * OpenRouter Service
 * AI Chatbot System — CBMS
 *
 * Handles all communication with OpenRouter AI API:
 * - Fetch & sync available models
 * - Chat completions (used by AIService)
 * - Embeddings
 * - Cost calculation
 */

namespace App\Services;

use App\Helpers\Database;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

class OpenRouterService
{
    private Client $http;
    private string $apiKey;
    private string $baseUrl;
    private string $siteUrl;
    private string $siteName;
    private Database $db;

    public function __construct()
    {
        $this->db       = Database::getInstance();
        $this->apiKey   = $_ENV['OPENROUTER_API_KEY']   ?? '';
        $this->baseUrl  = rtrim($_ENV['OPENROUTER_BASE_URL'] ?? 'https://openrouter.ai/api/v1', '/');
        $this->siteUrl  = $_ENV['OPENROUTER_SITE_URL']  ?? '';
        $this->siteName = $_ENV['OPENROUTER_SITE_NAME'] ?? 'AI Chatbot System';

        $this->http = new Client([
            'base_uri' => $this->baseUrl . '/',
            'timeout'  => 30,
            'headers'  => $this->buildHeaders(),
        ]);
    }

    // ----------------------------------------------------------------
    // 1. Fetch Available Models from OpenRouter API
    // ----------------------------------------------------------------

    /**
     * Fetch all available models from OpenRouter API.
     * Returns raw model array.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAvailableModels(): array
    {
        try {
            $response = $this->http->get('models');
            $body     = json_decode($response->getBody()->getContents(), true);
            return $body['data'] ?? [];
        } catch (GuzzleException $e) {
            $this->logError('fetchAvailableModels failed: ' . $e->getMessage());
            return [];
        }
    }

    // ----------------------------------------------------------------
    // 2. Sync Models to Database
    // ----------------------------------------------------------------

    /**
     * Sync models from OpenRouter API into the ai_models table.
     *
     * - Inserts new models
     * - Updates changed pricing/info
     * - Deactivates models no longer returned by API
     *
     * @return array{added: int, updated: int, deactivated: int, errors: int}
     */
    public function syncModelsToDatabase(): array
    {
        $stats    = ['added' => 0, 'updated' => 0, 'deactivated' => 0, 'errors' => 0];
        $apiModels = $this->fetchAvailableModels();

        if (empty($apiModels)) {
            return $stats;
        }

        // Build a set of model IDs returned by API
        $apiModelIds = array_column($apiModels, 'id');

        // Fetch existing models from DB keyed by openrouter_model_id
        $existing = $this->db->fetchAll('SELECT * FROM ai_models');
        $existingMap = array_column($existing, null, 'openrouter_model_id');

        foreach ($apiModels as $apiModel) {
            $modelId = $apiModel['id'] ?? '';
            if (empty($modelId)) {
                continue;
            }

            try {
                $data = $this->mapApiModelToDb($apiModel);

                if (isset($existingMap[$modelId])) {
                    // Update existing
                    $this->db->update('ai_models', $data, ['openrouter_model_id' => $modelId]);
                    $stats['updated']++;
                } else {
                    // Insert new (keep default sort_order = 0, is_active = 1)
                    $this->db->insert('ai_models', array_merge($data, [
                        'is_active'  => 1,
                        'is_default' => 0,
                        'sort_order' => 0,
                    ]));
                    $stats['added']++;
                }
            } catch (\Throwable $e) {
                $this->logError("syncModels error for {$modelId}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        // Deactivate models no longer in API response
        foreach ($existingMap as $modelId => $dbModel) {
            if (!in_array($modelId, $apiModelIds, true) && $dbModel['is_active']) {
                $this->db->update('ai_models', ['is_active' => 0], ['openrouter_model_id' => $modelId]);
                $stats['deactivated']++;
            }
        }

        return $stats;
    }

    // ----------------------------------------------------------------
    // 3. Chat Completion
    // ----------------------------------------------------------------

    /**
     * Send a chat completion request to OpenRouter.
     *
     * @param  string                  $model    OpenRouter model ID (e.g. "openai/gpt-4o-mini")
     * @param  array<int, array>       $messages [['role'=>'user','content'=>'...']]
     * @param  array<string, mixed>    $options  temperature, max_tokens, stream, etc.
     * @return array{content: string, model: string, usage: array, response_time_ms: int}
     *
     * @throws \RuntimeException on API error
     */
    public function chat(string $model, array $messages, array $options = []): array
    {
        $startTime = microtime(true);

        $temperature = (float)($options['temperature'] ?? 0.7);
        $maxTokens   = (int)($options['max_tokens']   ?? 1000);

        unset($options['temperature'], $options['max_tokens']);

        $payload = array_merge([
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
            'stream'      => false,
            // Disable model "thinking" — adds seconds of latency, little benefit
            // for KB-grounded FAQ answers.
            'reasoning'   => ['enabled' => false],
        ], $options);

        try {
            $response = $this->http->post('chat/completions', [
                'json' => $payload,
            ]);

            $body          = json_decode($response->getBody()->getContents(), true);
            $responseTimeMs = (int)((microtime(true) - $startTime) * 1000);

            return [
                'content'         => $body['choices'][0]['message']['content'] ?? '',
                'model'           => $body['model'] ?? $model,
                'usage'           => [
                    'prompt_tokens'     => $body['usage']['prompt_tokens']     ?? 0,
                    'completion_tokens' => $body['usage']['completion_tokens'] ?? 0,
                    'total_tokens'      => $body['usage']['total_tokens']      ?? 0,
                ],
                'response_time_ms' => $responseTimeMs,
            ];

        } catch (RequestException $e) {
            $responseTimeMs = (int)((microtime(true) - $startTime) * 1000);
            $errorBody = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : $e->getMessage();

            $this->logError("chat() failed for model {$model}: {$errorBody}");
            throw new \RuntimeException('OpenRouter chat request failed: ' . $errorBody);
        }
    }

    /**
     * Streaming chat completion. Reads the OpenRouter SSE stream and invokes
     * $onDelta for every token chunk as it arrives, then returns the fully
     * accumulated content + usage (same shape as chat()).
     *
     * @param  callable $onDelta  fn(string $textChunk): void — called per token
     * @return array{content: string, model: string, usage: array, response_time_ms: int}
     *
     * @throws \RuntimeException on API error
     */
    public function chatStream(string $model, array $messages, array $options, callable $onDelta): array
    {
        $startTime = microtime(true);

        $temperature = (float)($options['temperature'] ?? 0.7);
        $maxTokens   = (int)($options['max_tokens']   ?? 1000);

        unset($options['temperature'], $options['max_tokens']);

        $payload = array_merge([
            'model'          => $model,
            'messages'       => $messages,
            'temperature'    => $temperature,
            'max_tokens'     => $maxTokens,
            'stream'         => true,
            'stream_options' => ['include_usage' => true],
            // FAQ answers come from retrieved KB chunks — model "thinking" adds
            // several seconds of latency for little benefit. Disable it for speed.
            'reasoning'      => ['enabled' => false],
        ], $options);

        $content     = '';
        $actualModel = $model;
        $usage       = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $buffer      = '';
        $done        = false;

        // Raw cURL with a write callback: bytes are forwarded to $onDelta the
        // instant they arrive from OpenRouter. (Guzzle's stream wrapper buffered
        // the upstream read until completion, defeating token-by-token streaming.)
        $writeCallback = function ($ch, string $data) use (
            &$buffer, &$content, &$actualModel, &$usage, &$done, $onDelta
        ): int {
            $len = strlen($data);
            if ($done) {
                return $len;
            }
            $buffer .= $data;

            // Process every complete line in the buffer (SSE = newline-delimited)
            while (($nlPos = strpos($buffer, "\n")) !== false) {
                $line   = trim(substr($buffer, 0, $nlPos));
                $buffer = substr($buffer, $nlPos + 1);

                if ($line === '' || $line[0] === ':') {
                    continue; // blank line or SSE comment / keep-alive
                }
                if (strncmp($line, 'data:', 5) !== 0) {
                    continue;
                }

                $payloadLine = trim(substr($line, 5));
                if ($payloadLine === '[DONE]') {
                    $done = true;
                    break;
                }

                $json = json_decode($payloadLine, true);
                if (!is_array($json)) {
                    continue;
                }
                if (!empty($json['model'])) {
                    $actualModel = $json['model'];
                }
                $delta = $json['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    $content .= $delta;
                    $onDelta($delta);
                }
                if (isset($json['usage']) && is_array($json['usage'])) {
                    $usage['prompt_tokens']     = $json['usage']['prompt_tokens']     ?? $usage['prompt_tokens'];
                    $usage['completion_tokens'] = $json['usage']['completion_tokens'] ?? $usage['completion_tokens'];
                    $usage['total_tokens']      = $json['usage']['total_tokens']      ?? $usage['total_tokens'];
                }
            }

            return $len;
        };

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: text/event-stream',
            'HTTP-Referer: ' . $this->siteUrl,
            'X-Title: ' . $this->siteName,
        ];

        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TCP_NODELAY    => true,
            CURLOPT_BUFFERSIZE     => 1024,
        ]);
        // Set the write callback LAST and on its own — setting it inside
        // curl_setopt_array alongside RETURNTRANSFER can fail to register.
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, $writeCallback);

        $ok       = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 400) {
            $this->logError("chatStream() HTTP {$httpCode} for model {$model}: {$content}");
            throw new \RuntimeException("OpenRouter stream returned HTTP {$httpCode}");
        }
        if ($ok === false && $content === '') {
            $this->logError("chatStream() cURL failed for model {$model}: {$curlErr}");
            throw new \RuntimeException('OpenRouter stream request failed: ' . $curlErr);
        }

        return [
            'content'          => $content,
            'model'            => $actualModel,
            'usage'            => $usage,
            'response_time_ms' => (int)((microtime(true) - $startTime) * 1000),
        ];
    }

    // ----------------------------------------------------------------
    // 4. Create Embedding
    // ----------------------------------------------------------------

    /**
     * Create a text embedding vector.
     *
     * Defaults to OpenRouter's /embeddings, but the endpoint, key and model
     * can each be overridden via env so the system can talk straight to any
     * OpenAI-compatible embeddings API (OpenAI, Google AI Studio, VoyageAI,
     * self-hosted BGE-M3 ...) when OpenRouter doesn't carry the model wanted:
     *
     *   EMBEDDING_MODEL    — overrides OPENROUTER_EMBEDDING_MODEL
     *   EMBEDDING_BASE_URL — e.g. https://api.openai.com/v1
     *   EMBEDDING_API_KEY  — key for that endpoint
     *
     * IMPORTANT: after changing the model, run  php sync.php --re-embed-all
     * — stored vectors from the old model have a different dimension and
     * score 0 against new query vectors.
     *
     * @param  string|array $input Single string or array of strings
     * @return array  Embedding vector or array of embedding data objects
     */
    public function createEmbedding(string|array $input): array
    {
        $model = $_ENV['EMBEDDING_MODEL']
            ?? $_ENV['OPENROUTER_EMBEDDING_MODEL']
            ?? 'openai/text-embedding-3-small';

        $baseUrl = rtrim($_ENV['EMBEDDING_BASE_URL'] ?? '', '/');
        $apiKey  = $_ENV['EMBEDDING_API_KEY'] ?? '';

        try {
            if ($baseUrl !== '') {
                // Dedicated embeddings endpoint (OpenAI-compatible)
                $response = $this->http->post($baseUrl . '/embeddings', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . ($apiKey !== '' ? $apiKey : $this->apiKey),
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => ['model' => $model, 'input' => $input],
                ]);
            } else {
                $response = $this->http->post('embeddings', [
                    'json' => ['model' => $model, 'input' => $input],
                ]);
            }

            $body = json_decode($response->getBody()->getContents(), true);

            if (is_array($input)) {
                return $body['data'] ?? [];
            }
            return $body['data'][0]['embedding'] ?? [];

        } catch (GuzzleException $e) {
            $this->logError('createEmbedding failed: ' . $e->getMessage());
            return [];
        }
    }

    // ----------------------------------------------------------------
    // 5. Calculate Cost
    // ----------------------------------------------------------------

    /**
     * Calculate USD cost for a request.
     *
     * @param  string $modelId          openrouter_model_id
     * @param  int    $promptTokens
     * @param  int    $completionTokens
     * @return float  cost in USD
     */
    public function calculateCost(string $modelId, int $promptTokens, int $completionTokens): float
    {
        $model = $this->db->fetch(
            'SELECT pricing_prompt, pricing_completion FROM ai_models WHERE openrouter_model_id = ?',
            [$modelId]
        );

        if (!$model) {
            return 0.0;
        }

        return ($promptTokens     * max(0, (float)$model['pricing_prompt']))
             + ($completionTokens * max(0, (float)$model['pricing_completion']));
    }

    // ----------------------------------------------------------------
    // 6. Get Active Models
    // ----------------------------------------------------------------

    /**
     * Get all active models ordered by sort_order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveModels(): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ai_models WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        );
    }

    // ----------------------------------------------------------------
    // 7. Get Default Model
    // ----------------------------------------------------------------

    /**
     * Get the default AI model record, or null if none set.
     *
     * @return array<string, mixed>|null
     */
    public function getDefaultModel(): ?array
    {
        // First try DB is_default flag
        $model = $this->db->fetch(
            'SELECT * FROM ai_models WHERE is_default = 1 AND is_active = 1 LIMIT 1'
        );

        if ($model) {
            return $model;
        }

        // Fall back to .env default
        $defaultId = $_ENV['OPENROUTER_DEFAULT_MODEL'] ?? 'openai/gpt-4o-mini';
        $model = $this->db->fetch(
            'SELECT * FROM ai_models WHERE openrouter_model_id = ? AND is_active = 1 LIMIT 1',
            [$defaultId]
        );

        if ($model) {
            return $model;
        }

        // Last resort: first active model
        return $this->db->fetch(
            'SELECT * FROM ai_models WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 1'
        );
    }

    // ----------------------------------------------------------------
    // 8. Test Model
    // ----------------------------------------------------------------

    /**
     * Test a model with a short message to verify it's working.
     *
     * @param  string $modelId openrouter_model_id
     * @return array{success: bool, response_time_ms: int, response?: string, error?: string}
     */
    public function testModel(string $modelId): array
    {
        try {
            $result = $this->chat($modelId, [
                ['role' => 'user', 'content' => 'Reply with exactly: "OK"'],
            ], ['max_tokens' => 10, 'temperature' => 0]);

            return [
                'success'         => true,
                'response_time_ms'=> $result['response_time_ms'],
                'response'        => trim($result['content']),
            ];
        } catch (\Throwable $e) {
            return [
                'success'          => false,
                'response_time_ms' => 0,
                'error'            => $e->getMessage(),
            ];
        }
    }

    // ----------------------------------------------------------------
    // Private Helpers
    // ----------------------------------------------------------------

    private function buildHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'HTTP-Referer'  => $this->siteUrl,
            'X-Title'       => $this->siteName,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Map OpenRouter API model data to our DB schema.
     */
    private function mapApiModelToDb(array $apiModel): array
    {
        $pricing     = $apiModel['pricing'] ?? [];
        $topProvider = $apiModel['top_provider'] ?? [];
        $arch        = $apiModel['architecture'] ?? [];

        // Detect provider from model ID prefix
        $providerName = $this->extractProviderName($apiModel['id'] ?? '');

        return [
            'openrouter_model_id' => $apiModel['id']          ?? '',
            'display_name'        => $apiModel['name']         ?? $apiModel['id'] ?? '',
            'provider_name'       => $providerName,
            'description'         => $apiModel['description']  ?? null,
            'context_length'      => $apiModel['context_length'] ?? null,
            'pricing_prompt'      => max(0, (float)($pricing['prompt']     ?? 0)),
            'pricing_completion'  => max(0, (float)($pricing['completion'] ?? 0)),
            'max_tokens'          => $topProvider['max_completion_tokens'] ?? 4096,
            'supports_vision'     => in_array('image', $arch['input_modalities'] ?? [], true) ? 1 : 0,
            'supports_tools'      => !empty($apiModel['supported_parameters']) &&
                                     in_array('tools', $apiModel['supported_parameters'], true) ? 1 : 0,
            'last_fetched_at'     => date('Y-m-d H:i:s'),
        ];
    }

    private function extractProviderName(string $modelId): string
    {
        $providers = [
            'openai'      => 'OpenAI',
            'anthropic'   => 'Anthropic',
            'google'      => 'Google',
            'meta-llama'  => 'Meta',
            'mistralai'   => 'Mistral AI',
            'cohere'      => 'Cohere',
            'perplexity'  => 'Perplexity',
            'amazon'      => 'Amazon',
        ];

        $prefix = explode('/', $modelId)[0] ?? '';
        return $providers[$prefix] ?? ucfirst($prefix);
    }

    private function logError(string $message): void
    {
        $logPath = dirname(__DIR__, 2) . '/storage/logs/openrouter.log';
        @file_put_contents($logPath,
            sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message),
            FILE_APPEND | LOCK_EX
        );
    }
}
