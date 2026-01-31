<?php
/**
 * API Client for LLM and RAG services
 */

class ApiClient {
    private array $config;
    
    public function __construct(array $config) {
        $this->config = $config;
    }
    
    /**
     * Send a chat completion request to llama.cpp
     */
    public function chatCompletion(array $messages, ?string $model = null, ?float $temperature = null, ?int $maxTokens = null): array {
        $payload = [
            'model' => $model ?? $this->config['default_model'],
            'messages' => $messages,
            'temperature' => $temperature ?? $this->config['temperature'],
            'max_tokens' => $maxTokens ?? $this->config['max_tokens'],
            'stream' => false,
        ];
        
        return $this->post($this->config['llm_api'] . '/v1/chat/completions', $payload);
    }
    
    /**
     * Stream a chat completion (returns generator)
     */
    public function chatCompletionStream(array $messages, ?string $model = null, ?float $temperature = null, ?int $maxTokens = null): Generator {
        $payload = [
            'model' => $model ?? $this->config['default_model'],
            'messages' => $messages,
            'temperature' => $temperature ?? $this->config['temperature'],
            'max_tokens' => $maxTokens ?? $this->config['max_tokens'],
            'stream' => true,
        ];
        
        yield from $this->postStream($this->config['llm_api'] . '/v1/chat/completions', $payload);
    }
    
    /**
     * Query RAG server
     */
    public function ragQuery(string $query, string $collection = 'codebase', int $topK = 5, bool $includeSources = true): array {
        $payload = [
            'query' => $query,
            'collection' => $collection,
            'top_k' => $topK,
            'include_sources' => $includeSources,
        ];
        
        return $this->post($this->config['rag_api'] . '/query', $payload);
    }
    
    /**
     * List RAG collections
     */
    public function listCollections(): array {
        return $this->get($this->config['rag_api'] . '/collections');
    }
    
    /**
     * Check LLM server health
     */
    public function healthCheck(): array {
        $results = [
            'llm' => $this->checkEndpoint($this->config['llm_api'] . '/health'),
            'rag' => $this->checkEndpoint($this->config['rag_api'] . '/collections'),
        ];
        
        // Embedding server is optional
        $embedHealth = $this->checkEndpoint($this->config['embed_api'] . '/health');
        if ($embedHealth['status'] !== 'error') {
            $results['embed'] = $embedHealth;
        }
        
        return $results;
    }
    
    private function checkEndpoint(string $url): array {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return [
                'status' => $httpCode >= 200 && $httpCode < 300 ? 'ok' : 'error',
                'http_code' => $httpCode,
            ];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    private function post(string $url, array $data): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['error' => $error];
        }
        
        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            return ['error' => $decoded['detail'] ?? $decoded['error'] ?? "HTTP $httpCode", 'http_code' => $httpCode];
        }
        
        return $decoded ?? ['error' => 'Invalid JSON response'];
    }
    
    private function get(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['error' => $error];
        }
        
        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            return ['error' => $decoded['detail'] ?? $decoded['error'] ?? "HTTP $httpCode"];
        }
        
        return $decoded ?? ['error' => 'Invalid JSON response'];
    }
    
    private function postStream(string $url, array $data): Generator {
        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$buffer) {
                $buffer .= $data;
                return strlen($data);
            },
        ]);
        
        $buffer = '';
        curl_exec($ch);
        curl_close($ch);
        
        // Parse SSE events
        $lines = explode("\n", $buffer);
        foreach ($lines as $line) {
            if (str_starts_with($line, 'data: ')) {
                $json = substr($line, 6);
                if ($json === '[DONE]') {
                    break;
                }
                $decoded = json_decode($json, true);
                if ($decoded) {
                    yield $decoded;
                }
            }
        }
    }
}
