<?php
/**
 * EmberCortex Configuration
 */

return [
    // Backend service URLs (proxied through NGINX)
    'llm_api' => 'http://127.0.0.1:8080',
    'embed_api' => 'http://127.0.0.1:8081',
    'rag_api' => 'http://127.0.0.1:8082',
    
    // SQLite database path
    'db_path' => __DIR__ . '/../data/embercortex.db',
    
    // Default settings
    'default_collection' => 'codebase',
    'default_model' => 'qwen2.5-coder',
    'max_tokens' => 4096,
    'temperature' => 0.1,
    'context_length' => 16384,
    
    // App info
    'app_name' => 'EmberCortex',
    'version' => '0.1.0',
];
