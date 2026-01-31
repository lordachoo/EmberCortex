<?php
/**
 * EmberCortex - Main Chat Interface
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/api.php';

$config = require __DIR__ . '/../config/config.php';
Database::init($config['db_path']);
$api = new ApiClient($config);

// Get or create session ID
session_start();
if (!isset($_SESSION['chat_session_id'])) {
    $_SESSION['chat_session_id'] = bin2hex(random_bytes(16));
}
$sessionId = $_SESSION['chat_session_id'];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_HX_REQUEST'])) {
    header('Content-Type: text/html; charset=utf-8');
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'send_message') {
        $message = trim($_POST['message'] ?? '');
        $collection = $_POST['collection'] ?? null;
        $useRag = !empty($collection) && $collection !== 'none';
        
        if (empty($message)) {
            echo '<div class="text-red-400 text-sm">Please enter a message</div>';
            exit;
        }
        
        // Start timing
        $startTime = microtime(true);
        
        // Save user message
        Database::saveChatMessage($sessionId, 'user', $message, $collection);
        
        // Build messages array from history
        $history = Database::getChatHistory($sessionId, 20);
        $messages = [];
        foreach ($history as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }
        
        // Get response
        if ($useRag) {
            $ragResponse = $api->ragQuery($message, $collection);
            if (isset($ragResponse['error'])) {
                $assistantContent = "RAG Error: " . $ragResponse['error'];
            } else {
                $assistantContent = $ragResponse['answer'] ?? 'No response';
            }
        } else {
            $response = $api->chatCompletion($messages);
            if (isset($response['error'])) {
                $assistantContent = "Error: " . $response['error'];
            } else {
                $assistantContent = $response['choices'][0]['message']['content'] ?? 'No response';
            }
        }
        
        // Calculate response time
        $responseTime = round(microtime(true) - $startTime, 2);
        
        // Save assistant message with response time
        Database::saveChatMessage($sessionId, 'assistant', $assistantContent, $collection, null, $responseTime);
        
        // Trigger sidebar refresh (must be before any output)
        header('HX-Trigger: refreshSidebar');
        
        // Return both messages as HTML with response time
        echo renderMessage('user', $message);
        echo renderMessage('assistant', $assistantContent, $responseTime, $useRag ? $collection : null);
        exit;
    }
    
    if ($action === 'new_chat') {
        $_SESSION['chat_session_id'] = bin2hex(random_bytes(16));
        header('HX-Trigger: refreshSidebar');
        echo '';
        exit;
    }
    
    if ($action === 'delete_chat') {
        $deleteSessionId = $_POST['session_id'] ?? '';
        if ($deleteSessionId) {
            Database::deleteSession($deleteSessionId);
            // If deleting current session, start a new one
            if ($deleteSessionId === $sessionId) {
                $_SESSION['chat_session_id'] = bin2hex(random_bytes(16));
            }
        }
        header('HX-Trigger: refreshSidebar');
        exit;
    }
    
    if ($action === 'load_session') {
        $loadSessionId = $_POST['session_id'] ?? '';
        if ($loadSessionId) {
            $_SESSION['chat_session_id'] = $loadSessionId;
            $history = Database::getChatHistory($loadSessionId);
            foreach ($history as $msg) {
                echo renderMessage($msg['role'], $msg['content']);
            }
        }
        exit;
    }
    
    if ($action === 'health_check') {
        $health = $api->healthCheck();
        echo '<div class="text-xs space-y-1">';
        foreach ($health as $service => $status) {
            $color = $status['status'] === 'ok' ? 'text-green-400' : 'text-red-400';
            $icon = $status['status'] === 'ok' ? '●' : '○';
            echo "<div class=\"$color\">$icon " . ucfirst($service) . "</div>";
        }
        echo '</div>';
        exit;
    }
    
    if ($action === 'get_collections') {
        $collections = $api->listCollections();
        if (isset($collections['error'])) {
            echo '<option value="none">Direct LLM (no RAG)</option>';
            echo '<option disabled>-- RAG unavailable --</option>';
        } else {
            echo '<option value="none">Direct LLM (no RAG)</option>';
            foreach ($collections['collections'] ?? [] as $col) {
                $name = htmlspecialchars($col['name']);
                echo "<option value=\"$name\">RAG: $name</option>";
            }
        }
        exit;
    }
    
    if ($action === 'get_recent_chats') {
        $recentSessions = Database::getRecentSessions(10);
        if (empty($recentSessions)) {
            echo '<div class="text-gray-500 text-xs">No recent chats</div>';
        } else {
            foreach ($recentSessions as $sess) {
                $sessId = htmlspecialchars($sess['session_id']);
                $firstMsg = htmlspecialchars($sess['first_message'] ?? 'Chat');
                $truncated = htmlspecialchars(substr($sess['first_message'] ?? 'Chat', 0, 25));
                echo <<<HTML
                <div class="group flex items-center gap-1">
                    <button 
                        hx-post="/"
                        hx-vals='{"action": "load_session", "session_id": "$sessId"}'
                        hx-target="#chat-messages"
                        hx-swap="innerHTML"
                        class="flex-1 text-left p-2 rounded hover:bg-gray-800 text-sm truncate text-gray-400 hover:text-gray-200 transition"
                        title="$firstMsg"
                    >$truncated...</button>
                    <button 
                        hx-post="/"
                        hx-vals='{"action": "delete_chat", "session_id": "$sessId"}'
                        hx-target="#chat-messages"
                        hx-swap="innerHTML"
                        hx-confirm="Delete this chat?"
                        class="opacity-0 group-hover:opacity-100 p-1 text-gray-500 hover:text-red-400 transition"
                        title="Delete chat"
                    >×</button>
                </div>
                HTML;
            }
        }
        exit;
    }
    
    exit;
}

function renderMessage(string $role, string $content, ?float $responseTime = null, ?string $collection = null): string {
    $isUser = $role === 'user';
    $alignClass = $isUser ? 'ml-auto max-w-3xl' : 'mr-auto max-w-3xl';
    $bgClass = $isUser ? 'bg-blue-900/30' : 'bg-gray-800';
    $label = $isUser ? 'You' : 'Assistant';
    $labelColor = $isUser ? 'text-blue-400' : 'text-emerald-400';
    $uniqueId = 'msg-' . bin2hex(random_bytes(8));
    
    // Escape content for safe embedding in data attribute
    $escapedContent = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
    
    // Build metadata line for assistant messages
    $metaInfo = '';
    if (!$isUser) {
        $metaParts = [];
        if ($responseTime !== null) {
            $metaParts[] = "{$responseTime}s";
        }
        if ($collection) {
            $metaParts[] = "RAG: " . htmlspecialchars($collection);
        }
        if (!empty($metaParts)) {
            $metaInfo = '<span class="text-gray-500 text-xs ml-2">(' . implode(' · ', $metaParts) . ')</span>';
        }
    }
    
    // For user messages, just escape and show. For assistant, render markdown client-side
    if ($isUser) {
        $displayContent = nl2br($escapedContent);
        return <<<HTML
        <div class="$alignClass $bgClass rounded-lg p-4">
            <div class="text-xs $labelColor mb-1 font-semibold">$label</div>
            <div class="text-gray-100">$displayContent</div>
        </div>
        HTML;
    }
    
    // Assistant message - render markdown client-side
    return <<<HTML
    <div class="$alignClass $bgClass rounded-lg p-4">
        <div class="text-xs $labelColor mb-1 font-semibold">$label$metaInfo</div>
        <div id="$uniqueId" class="text-gray-100 prose prose-invert prose-sm max-w-none markdown-content" data-raw="$escapedContent"></div>
    </div>
    <script>renderMarkdown('$uniqueId');</script>
    HTML;
}

// Get chat history for initial render
$chatHistory = Database::getChatHistory($sessionId);
$recentSessions = Database::getRecentSessions(10);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($config['app_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/htmx.org@1.9.10"></script>
    <!-- Markdown rendering -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <!-- Syntax highlighting -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.9.0/build/styles/github-dark.min.css">
    <script src="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.9.0/build/highlight.min.js"></script>
    <style>
        .htmx-request .htmx-indicator { display: inline-block; }
        .htmx-indicator { display: none; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .animate-pulse { animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        
        /* Markdown prose styling */
        .markdown-content h1 { font-size: 1.5em; font-weight: bold; margin: 1em 0 0.5em; }
        .markdown-content h2 { font-size: 1.3em; font-weight: bold; margin: 1em 0 0.5em; }
        .markdown-content h3 { font-size: 1.1em; font-weight: bold; margin: 1em 0 0.5em; }
        .markdown-content p { margin: 0.5em 0; }
        .markdown-content ul, .markdown-content ol { margin: 0.5em 0; padding-left: 1.5em; }
        .markdown-content li { margin: 0.25em 0; }
        .markdown-content ul { list-style-type: disc; }
        .markdown-content ol { list-style-type: decimal; }
        .markdown-content code:not(pre code) { 
            background: #1f2937; 
            padding: 0.2em 0.4em; 
            border-radius: 4px; 
            font-size: 0.9em;
        }
        .markdown-content pre { 
            background: #0d1117; 
            padding: 1em; 
            border-radius: 8px; 
            overflow-x: auto; 
            margin: 1em 0;
        }
        .markdown-content pre code {
            background: none;
            padding: 0;
            font-size: 0.875em;
            line-height: 1.5;
        }
        .markdown-content blockquote {
            border-left: 3px solid #4b5563;
            padding-left: 1em;
            margin: 1em 0;
            color: #9ca3af;
        }
        .markdown-content a { color: #60a5fa; text-decoration: underline; }
        .markdown-content table { border-collapse: collapse; margin: 1em 0; }
        .markdown-content th, .markdown-content td { border: 1px solid #374151; padding: 0.5em 1em; }
        .markdown-content th { background: #1f2937; }
        .markdown-content hr { border: none; border-top: 1px solid #374151; margin: 1em 0; }
    </style>
    <script>
        // Configure marked
        marked.setOptions({
            highlight: function(code, lang) {
                if (lang && hljs.getLanguage(lang)) {
                    return hljs.highlight(code, { language: lang }).value;
                }
                return hljs.highlightAuto(code).value;
            },
            breaks: true,
            gfm: true
        });
        
        function renderMarkdown(elementId) {
            const el = document.getElementById(elementId);
            if (el && el.dataset.raw) {
                el.innerHTML = marked.parse(el.dataset.raw);
                // Re-highlight any code blocks that might have been missed
                el.querySelectorAll('pre code').forEach((block) => {
                    hljs.highlightElement(block);
                });
            }
        }
        
        // Render any existing markdown content on page load
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.markdown-content[data-raw]').forEach(function(el) {
                renderMarkdown(el.id);
            });
        });
    </script>
</head>
<body class="h-full bg-gray-900 text-gray-100">
    <div class="h-full flex">
        <!-- Sidebar -->
        <div class="w-64 bg-gray-950 border-r border-gray-800 flex flex-col">
            <!-- Logo -->
            <div class="p-4 border-b border-gray-800">
                <h1 class="text-xl font-bold text-emerald-400">🔥 <?= htmlspecialchars($config['app_name']) ?></h1>
                <p class="text-xs text-gray-500 mt-1">v<?= htmlspecialchars($config['version']) ?></p>
            </div>
            
            <!-- Navigation -->
            <nav class="p-3 space-y-1 border-b border-gray-800">
                <a href="/" class="block px-3 py-2 rounded bg-gray-800 text-white text-sm">
                    💬 Chat
                </a>
                <a href="/collections.php" class="block px-3 py-2 rounded hover:bg-gray-800 text-gray-400 hover:text-white text-sm transition">
                    📚 Collections
                </a>
            </nav>
            
            <!-- New Chat Button -->
            <div class="p-3">
                <button 
                    hx-post="/" 
                    hx-vals='{"action": "new_chat"}'
                    hx-target="#chat-messages"
                    hx-swap="innerHTML"
                    class="w-full py-2 px-4 bg-emerald-600 hover:bg-emerald-700 rounded-lg text-sm font-medium transition"
                >
                    + New Chat
                </button>
            </div>
            
            <!-- Recent Sessions -->
            <div class="flex-1 overflow-y-auto p-3">
                <div class="text-xs text-gray-500 uppercase tracking-wide mb-2">Recent Chats</div>
                <div id="recent-chats" class="space-y-1"
                    hx-post="/"
                    hx-vals='{"action": "get_recent_chats"}'
                    hx-trigger="load, refreshSidebar from:body"
                    hx-swap="innerHTML"
                >
                    <?php foreach ($recentSessions as $sess): ?>
                    <button 
                        hx-post="/"
                        hx-vals='{"action": "load_session", "session_id": "<?= htmlspecialchars($sess['session_id']) ?>"}'
                        hx-target="#chat-messages"
                        hx-swap="innerHTML"
                        class="w-full text-left p-2 rounded hover:bg-gray-800 text-sm truncate text-gray-400 hover:text-gray-200 transition"
                        title="<?= htmlspecialchars($sess['first_message'] ?? 'Chat') ?>"
                    >
                        <?= htmlspecialchars(substr($sess['first_message'] ?? 'Chat', 0, 30)) ?>...
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Status -->
            <div class="p-3 border-t border-gray-800">
                <div class="text-xs text-gray-500 uppercase tracking-wide mb-2">Services</div>
                <div 
                    hx-post="/"
                    hx-vals='{"action": "health_check"}'
                    hx-trigger="load, every 30s"
                    hx-swap="innerHTML"
                >
                    <div class="text-xs text-gray-600">Checking...</div>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
            <!-- Chat Messages -->
            <div id="chat-messages" class="flex-1 overflow-y-auto p-4 space-y-4">
                <?php if (empty($chatHistory)): ?>
                <div class="text-center text-gray-500 mt-20">
                    <div class="text-4xl mb-4">🔥</div>
                    <div class="text-lg">Welcome to <?= htmlspecialchars($config['app_name']) ?></div>
                    <div class="text-sm mt-2">Start a conversation with your local LLM</div>
                </div>
                <?php else: ?>
                    <?php foreach ($chatHistory as $msg): ?>
                        <?= renderMessage($msg['role'], $msg['content'], $msg['response_time'] ?? null, $msg['collection'] ?? null) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Input Area -->
            <div class="border-t border-gray-800 p-4 bg-gray-950">
                <form 
                    hx-post="/"
                    hx-target="#chat-messages"
                    hx-swap="beforeend"
                    hx-on::after-request="document.querySelector('input[name=message]').value=''; document.getElementById('chat-messages').scrollTop = document.getElementById('chat-messages').scrollHeight;"
                    class="flex gap-3"
                >
                    <input type="hidden" name="action" value="send_message">
                    
                    <!-- Collection Selector -->
                    <select 
                        id="collection-select"
                        name="collection" 
                        class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-emerald-500"
                        onchange="localStorage.setItem('selectedCollection', this.value)"
                    >
                        <option value="none">Loading...</option>
                    </select>
                    <script>
                        // Load collections on page load
                        fetch('/', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'HX-Request': 'true'},
                            body: 'action=get_collections'
                        })
                        .then(r => r.text())
                        .then(html => {
                            const select = document.getElementById('collection-select');
                            select.innerHTML = html;
                            // Restore saved selection
                            const saved = localStorage.getItem('selectedCollection');
                            if (saved && select.querySelector('option[value="' + saved + '"]')) {
                                select.value = saved;
                            }
                        })
                        .catch(() => { document.getElementById('collection-select').innerHTML = '<option value="none">Direct LLM (no RAG)</option><option disabled>-- Error loading --</option>'; });
                    </script>
                    
                    <!-- Message Input -->
                    <input 
                        type="text" 
                        name="message" 
                        placeholder="Type your message..." 
                        autocomplete="off"
                        class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 focus:outline-none focus:border-emerald-500"
                    >
                    
                    <!-- Send Button -->
                    <button 
                        type="submit"
                        class="px-6 py-2 bg-emerald-600 hover:bg-emerald-700 rounded-lg font-medium transition flex items-center gap-2 disabled:opacity-50"
                        id="send-btn"
                    >
                        <span id="send-text">Send</span>
                        <span id="send-spinner" class="hidden animate-spin">⏳</span>
                    </button>
                    <script>
                        document.querySelector('form').addEventListener('htmx:beforeRequest', function() {
                            document.getElementById('send-btn').disabled = true;
                            document.getElementById('send-text').textContent = 'Thinking...';
                            document.getElementById('send-spinner').classList.remove('hidden');
                        });
                        document.querySelector('form').addEventListener('htmx:afterRequest', function() {
                            document.getElementById('send-btn').disabled = false;
                            document.getElementById('send-text').textContent = 'Send';
                            document.getElementById('send-spinner').classList.add('hidden');
                        });
                    </script>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
