<?php
/**
 * EmberCortex - RAG Collections Management
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/api.php';

$config = require __DIR__ . '/../config/config.php';
Database::init($config['db_path']);
$api = new ApiClient($config);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_HX_REQUEST'])) {
    header('Content-Type: text/html; charset=utf-8');
    
    $action = $_POST['action'] ?? '';
    
    // List all collections
    if ($action === 'list_collections') {
        $collections = $api->listCollections();
        if (isset($collections['error'])) {
            echo '<div class="text-red-400 p-4">Error: ' . htmlspecialchars($collections['error']) . '</div>';
            exit;
        }
        
        if (empty($collections['collections'])) {
            echo '<div class="text-gray-500 p-8 text-center">No collections yet. Create one to get started.</div>';
            exit;
        }
        
        foreach ($collections['collections'] as $col) {
            $name = htmlspecialchars($col['name']);
            $count = $col['count'] ?? 0;
            $desc = htmlspecialchars($col['metadata']['description'] ?? 'No description');
            $source = htmlspecialchars($col['metadata']['source'] ?? '');
            $sourceJson = htmlspecialchars(json_encode($source), ENT_QUOTES);
            echo <<<HTML
            <div class="bg-gray-800 rounded-lg p-4 flex items-center justify-between group">
                <div class="flex-1">
                    <div class="flex items-center gap-3">
                        <span class="text-emerald-400 font-semibold text-lg">$name</span>
                        <span class="text-xs bg-gray-700 px-2 py-1 rounded">$count chunks</span>
                    </div>
                    <div class="text-gray-400 text-sm mt-1">$desc</div>
                </div>
                <div class="flex gap-2 opacity-0 group-hover:opacity-100 transition">
                    <button 
                        hx-post="/collections.php"
                        hx-vals='{"action": "view_collection", "name": "$name", "source": $sourceJson, "description": "$desc"}'
                        hx-target="#collection-detail"
                        class="px-3 py-1 bg-blue-600 hover:bg-blue-700 rounded text-sm"
                    >View</button>
                    <button 
                        hx-post="/collections.php"
                        hx-vals='{"action": "edit_collection", "name": "$name", "source": $sourceJson, "description": "$desc"}'
                        hx-target="#collection-detail"
                        class="px-3 py-1 bg-yellow-600 hover:bg-yellow-700 rounded text-sm"
                    >Edit</button>
                    <button 
                        hx-post="/collections.php"
                        hx-vals='{"action": "delete_collection", "name": "$name"}'
                        hx-target="this"
                        hx-swap="none"
                        hx-confirm="Delete collection '$name'? This cannot be undone."
                        class="px-3 py-1 bg-red-600 hover:bg-red-700 rounded text-sm"
                    >Delete</button>
                </div>
            </div>
            HTML;
        }
        exit;
    }
    
    // Create new collection
    if ($action === 'create_collection') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if (empty($name)) {
            echo '<div class="text-red-400 text-sm">Collection name is required</div>';
            exit;
        }
        
        // Sanitize name (alphanumeric, underscores, hyphens only)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            echo '<div class="text-red-400 text-sm">Name can only contain letters, numbers, underscores, and hyphens</div>';
            exit;
        }
        
        $result = createCollection($config['rag_api'], $name, $description);
        if (isset($result['error'])) {
            echo '<div class="text-red-400 text-sm">Error: ' . htmlspecialchars($result['error']) . '</div>';
        } else {
            echo '<div class="text-emerald-400 text-sm">Collection created successfully!</div>';
        }
        exit;
    }
    
    // Delete collection
    if ($action === 'delete_collection') {
        $name = $_POST['name'] ?? '';
        $result = deleteCollection($config['rag_api'], $name);
        
        // Clear detail panel and refresh list via header
        header('HX-Trigger: refreshList');
        echo '<div id="collection-detail"></div>';
        exit;
    }
    
    // View collection details
    if ($action === 'view_collection') {
        $name = htmlspecialchars($_POST['name'] ?? '');
        $source = htmlspecialchars($_POST['source'] ?? '');
        echo <<<HTML
        <div class="bg-gray-800 rounded-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl font-bold text-emerald-400">$name</h3>
                <button 
                    hx-post="/collections.php"
                    hx-vals='{"action": "clear_detail"}'
                    hx-target="#collection-detail"
                    class="text-gray-400 hover:text-white"
                >&times; Close</button>
            </div>
            
            <div class="mb-6">
                <h4 class="text-sm font-semibold text-gray-400 mb-2">Ingest Documents</h4>
                <form 
                    hx-post="/collections.php"
                    hx-target="#ingest-result"
                    class="space-y-3"
                >
                    <input type="hidden" name="action" value="ingest_directory">
                    <input type="hidden" name="collection" value="$name">
                    <div>
                        <label class="text-xs text-gray-500">Directory Path (on server)</label>
                        <input 
                            type="text" 
                            name="directory" 
                            value="$source"
                            placeholder="/home/anelson/projects/my-project"
                            class="w-full bg-gray-900 border border-gray-700 rounded px-3 py-2 text-sm mt-1"
                        >
                    </div>
                    <div class="flex gap-2 flex-wrap">
                        <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 rounded text-sm ingest-btn">
                            Ingest Directory
                        </button>
                        <button 
                            type="button"
                            onclick="if(confirm('Clear all documents and re-ingest? This cannot be undone.')) { showIngestProgress(); htmx.ajax('POST', '/collections.php', {target:'#ingest-result', values:{action:'clear_and_ingest', collection:'$name', directory:this.form.directory.value}}).then(hideIngestProgress); }"
                            class="px-4 py-2 bg-orange-600 hover:bg-orange-700 rounded text-sm ingest-btn"
                        >
                            Clear & Re-ingest
                        </button>
                        <span class="text-xs text-green-500 self-center">✓ GPU-accelerated</span>
                    </div>
                </form>
                <div id="ingest-result" class="mt-3"></div>
                <script>
                    document.querySelector('form[hx-target=\"#ingest-result\"]')?.addEventListener('htmx:beforeRequest', showIngestProgress);
                    document.querySelector('form[hx-target=\"#ingest-result\"]')?.addEventListener('htmx:afterRequest', hideIngestProgress);
                </script>
            </div>
            
            <div class="mb-6">
                <h4 class="text-sm font-semibold text-gray-400 mb-2">View Chunks</h4>
                <button 
                    hx-post="/collections.php"
                    hx-vals='{"action": "view_chunks", "collection": "$name"}'
                    hx-target="#chunks-result"
                    class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded text-sm"
                >
                    Show Documents/Chunks
                </button>
                <div id="chunks-result" class="mt-3 max-h-64 overflow-y-auto"></div>
            </div>
            
            <div>
                <h4 class="text-sm font-semibold text-gray-400 mb-2">Test Query</h4>
                <form 
                    hx-post="/collections.php"
                    hx-target="#query-result"
                    class="space-y-3"
                >
                    <input type="hidden" name="action" value="test_query">
                    <input type="hidden" name="collection" value="$name">
                    <input 
                        type="text" 
                        name="query" 
                        placeholder="Ask a question about the documents..."
                        class="w-full bg-gray-900 border border-gray-700 rounded px-3 py-2 text-sm"
                    >
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded text-sm">
                        Test Query
                    </button>
                </form>
                <div id="query-result" class="mt-3"></div>
            </div>
        </div>
        HTML;
        exit;
    }
    
    // Clear detail panel
    if ($action === 'clear_detail') {
        echo '';
        exit;
    }
    
    // Edit collection form
    if ($action === 'edit_collection') {
        $name = htmlspecialchars($_POST['name'] ?? '');
        $source = htmlspecialchars($_POST['source'] ?? '');
        $description = htmlspecialchars($_POST['description'] ?? '');
        echo <<<HTML
        <div class="bg-gray-800 rounded-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl font-bold text-yellow-400">Edit: $name</h3>
                <button 
                    hx-post="/collections.php"
                    hx-vals='{"action": "clear_detail"}'
                    hx-target="#collection-detail"
                    class="text-gray-400 hover:text-white"
                >&times; Close</button>
            </div>
            
            <form 
                hx-post="/collections.php"
                hx-target="#edit-result"
                class="space-y-4"
            >
                <input type="hidden" name="action" value="save_collection">
                <input type="hidden" name="name" value="$name">
                
                <div>
                    <label class="text-xs text-gray-500">Description</label>
                    <input 
                        type="text" 
                        name="description" 
                        value="$description"
                        placeholder="Brief description of this collection"
                        class="w-full bg-gray-900 border border-gray-700 rounded px-3 py-2 text-sm mt-1"
                    >
                </div>
                
                <div>
                    <label class="text-xs text-gray-500">Source Directory</label>
                    <input 
                        type="text" 
                        name="source" 
                        value="$source"
                        placeholder="/path/to/source"
                        class="w-full bg-gray-900 border border-gray-700 rounded px-3 py-2 text-sm mt-1"
                    >
                </div>
                
                <button type="submit" class="px-4 py-2 bg-yellow-600 hover:bg-yellow-700 rounded text-sm">
                    Save Changes
                </button>
            </form>
            <div id="edit-result" class="mt-3"></div>
        </div>
        HTML;
        exit;
    }
    
    // Save collection metadata
    if ($action === 'save_collection') {
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $source = $_POST['source'] ?? '';
        
        $result = updateCollection($config['rag_api'], $name, $description, $source);
        if (isset($result['error'])) {
            echo '<div class="text-red-400 text-sm">Error: ' . htmlspecialchars($result['error']) . '</div>';
        } else {
            echo '<div class="text-emerald-400 text-sm mb-2">Collection updated successfully!</div>';
            echo '<button hx-post="/collections.php" hx-vals=\'{"action": "clear_detail"}\' hx-target="#collection-detail" class="text-xs text-gray-400 hover:text-white">← Back to list</button>';
        }
        // Always trigger list refresh via header
        header('HX-Trigger: refreshList');
        exit;
    }
    
    // Ingest directory
    if ($action === 'ingest_directory') {
        $collection = $_POST['collection'] ?? '';
        $directory = $_POST['directory'] ?? '';
        
        if (empty($directory)) {
            echo '<div class="text-red-400 text-sm">Directory path is required</div>';
            exit;
        }
        
        $result = ingestDirectory($config['rag_api'], $collection, $directory);
        if (isset($result['error'])) {
            echo '<div class="text-red-400 text-sm">Error: ' . htmlspecialchars($result['error']) . '</div>';
        } else {
            $docs = $result['documents_ingested'] ?? 0;
            $skipped = $result['documents_skipped'] ?? 0;
            $chunks = $result['total_chunks'] ?? 0;
            $msg = $result['message'] ?? '';
            if ($msg) {
                echo "<div class='text-yellow-400 text-sm'>$msg</div>";
            } else {
                echo "<div class='text-emerald-400 text-sm'>Ingested $docs new documents, skipped $skipped duplicates ($chunks total chunks)</div>";
            }
            // Refresh list to show updated chunk count
            header('HX-Trigger: refreshList');
        }
        exit;
    }
    
    // View chunks
    if ($action === 'view_chunks') {
        $collection = $_POST['collection'] ?? '';
        $result = getChunks($config['rag_api'], $collection);
        
        if (isset($result['error'])) {
            echo '<div class="text-red-400 text-sm">Error: ' . htmlspecialchars($result['error']) . '</div>';
            exit;
        }
        
        $total = $result['total'] ?? 0;
        $chunks = $result['chunks'] ?? [];
        
        if (empty($chunks)) {
            echo '<div class="text-gray-500 text-sm">No documents in this collection yet.</div>';
            exit;
        }
        
        echo "<div class='text-xs text-gray-500 mb-2'>Showing " . count($chunks) . " of $total chunks</div>";
        echo "<div class='space-y-2'>";
        foreach ($chunks as $chunk) {
            $text = htmlspecialchars($chunk['text']);
            $file = htmlspecialchars($chunk['metadata']['file_path'] ?? 'Unknown');
            $file = basename($file);
            echo "<div class='bg-gray-900 rounded p-2 text-xs'>";
            echo "<div class='text-emerald-400 font-mono mb-1'>$file</div>";
            echo "<div class='text-gray-400 whitespace-pre-wrap'>$text</div>";
            echo "</div>";
        }
        echo "</div>";
        exit;
    }
    
    // Clear and re-ingest
    if ($action === 'clear_and_ingest') {
        $collection = $_POST['collection'] ?? '';
        $directory = $_POST['directory'] ?? '';
        
        if (empty($directory)) {
            echo '<div class="text-red-400 text-sm">Directory path is required</div>';
            exit;
        }
        
        // First clear the collection
        $clearResult = clearCollection($config['rag_api'], $collection);
        if (isset($clearResult['error'])) {
            echo '<div class="text-red-400 text-sm">Error clearing: ' . htmlspecialchars($clearResult['error']) . '</div>';
            exit;
        }
        
        // Then ingest
        $result = ingestDirectory($config['rag_api'], $collection, $directory);
        if (isset($result['error'])) {
            echo '<div class="text-red-400 text-sm">Error ingesting: ' . htmlspecialchars($result['error']) . '</div>';
        } else {
            $docs = $result['documents_ingested'] ?? 0;
            $chunks = $result['total_chunks'] ?? 0;
            echo "<div class='text-emerald-400 text-sm'>Cleared and re-ingested $docs documents ($chunks total chunks)</div>";
            // Refresh list to show updated chunk count
            header('HX-Trigger: refreshList');
        }
        exit;
    }
    
    // Test query
    if ($action === 'test_query') {
        $collection = $_POST['collection'] ?? '';
        $query = $_POST['query'] ?? '';
        
        if (empty($query)) {
            echo '<div class="text-red-400 text-sm">Query is required</div>';
            exit;
        }
        
        $result = $api->ragQuery($query, $collection, 3, true);
        if (isset($result['error'])) {
            echo '<div class="text-red-400 text-sm">Error: ' . htmlspecialchars($result['error']) . '</div>';
            exit;
        }
        
        $answer = htmlspecialchars($result['answer'] ?? 'No answer');
        echo "<div class='bg-gray-900 rounded p-3 text-sm'>";
        echo "<div class='text-gray-300 mb-3'>$answer</div>";
        
        if (!empty($result['sources'])) {
            echo "<div class='text-xs text-gray-500 border-t border-gray-700 pt-2 mt-2'>";
            echo "<div class='font-semibold mb-1'>Sources:</div>";
            foreach ($result['sources'] as $i => $src) {
                $score = number_format($src['score'] ?? 0, 3);
                $text = htmlspecialchars(substr($src['text'] ?? '', 0, 150)) . '...';
                echo "<div class='mb-1'>[$score] $text</div>";
            }
            echo "</div>";
        }
        echo "</div>";
        exit;
    }
    
    exit;
}

// Helper functions for RAG API calls
function createCollection(string $ragApi, string $name, string $description): array {
    $ch = curl_init("$ragApi/collections/$name?description=" . urlencode($description));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return ['error' => $error];
    return json_decode($response, true) ?? ['error' => 'Invalid response'];
}

function deleteCollection(string $ragApi, string $name): array {
    $ch = curl_init("$ragApi/collections/$name");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return ['error' => $error];
    return json_decode($response, true) ?? ['error' => 'Invalid response'];
}

function ingestDirectory(string $ragApi, string $collection, string $directory): array {
    $ch = curl_init("$ragApi/ingest/directory");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['collection' => $collection, 'directory' => $directory]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 600, // Ingestion can take a while
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return ['error' => $error];
    return json_decode($response, true) ?? ['error' => 'Invalid response'];
}

function getChunks(string $ragApi, string $collection, int $limit = 50): array {
    $ch = curl_init("$ragApi/collections/$collection/chunks?limit=$limit");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return ['error' => $error];
    return json_decode($response, true) ?? ['error' => 'Invalid response'];
}

function clearCollection(string $ragApi, string $collection): array {
    $ch = curl_init("$ragApi/collections/$collection/clear");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return ['error' => $error];
    return json_decode($response, true) ?? ['error' => 'Invalid response'];
}

function updateCollection(string $ragApi, string $collection, string $description, string $source): array {
    $ch = curl_init("$ragApi/collections/$collection");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode(['description' => $description, 'source' => $source]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return ['error' => $error];
    return json_decode($response, true) ?? ['error' => 'Invalid response'];
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collections - <?= htmlspecialchars($config['app_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/htmx.org@1.9.10"></script>
</head>
<body class="h-full bg-gray-900 text-gray-100">
    <div class="h-full flex">
        <!-- Sidebar -->
        <div class="w-64 bg-gray-950 border-r border-gray-800 flex flex-col">
            <div class="p-4 border-b border-gray-800">
                <h1 class="text-xl font-bold text-emerald-400">🔥 <?= htmlspecialchars($config['app_name']) ?></h1>
                <p class="text-xs text-gray-500 mt-1">RAG Collections</p>
            </div>
            
            <nav class="p-3 space-y-1">
                <a href="/" class="block px-3 py-2 rounded hover:bg-gray-800 text-gray-400 hover:text-white transition">
                    💬 Chat
                </a>
                <a href="/collections.php" class="block px-3 py-2 rounded bg-gray-800 text-white">
                    📚 Collections
                </a>
            </nav>
            
            <div class="flex-1"></div>
            
            <div class="p-3 border-t border-gray-800 text-xs text-gray-600">
                Manage your RAG document collections
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <!-- Header -->
            <div class="border-b border-gray-800 p-4 bg-gray-950">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold">Document Collections</h2>
                    <button 
                        onclick="document.getElementById('create-modal').classList.remove('hidden')"
                        class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 rounded-lg text-sm font-medium transition"
                    >
                        + New Collection
                    </button>
                </div>
            </div>
            
            <!-- Content -->
            <div class="flex-1 overflow-auto p-4">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <!-- Collections List -->
                    <div>
                        <h3 class="text-sm font-semibold text-gray-400 mb-3">Your Collections</h3>
                        <div 
                            id="collections-list" 
                            class="space-y-2"
                            hx-post="/collections.php"
                            hx-vals='{"action": "list_collections"}'
                            hx-trigger="load"
                        >
                            <div class="text-gray-600 text-sm">Loading...</div>
                        </div>
                    </div>
                    
                    <!-- Collection Detail -->
                    <div id="collection-detail">
                        <div class="text-gray-500 text-sm p-8 text-center">
                            Select a collection to view details and ingest documents.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create Collection Modal -->
    <div id="create-modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
        <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h3 class="text-lg font-semibold mb-4">Create New Collection</h3>
            <form 
                hx-post="/collections.php"
                hx-target="#create-result"
                hx-on::after-request="if(event.detail.successful) { document.getElementById('collections-list').dispatchEvent(new Event('refresh')); }"
            >
                <input type="hidden" name="action" value="create_collection">
                <div class="space-y-4">
                    <div>
                        <label class="text-sm text-gray-400">Collection Name</label>
                        <input 
                            type="text" 
                            name="name" 
                            placeholder="my_codebase"
                            class="w-full bg-gray-900 border border-gray-700 rounded px-3 py-2 mt-1"
                            required
                        >
                    </div>
                    <div>
                        <label class="text-sm text-gray-400">Description (optional)</label>
                        <input 
                            type="text" 
                            name="description" 
                            placeholder="My project source code"
                            class="w-full bg-gray-900 border border-gray-700 rounded px-3 py-2 mt-1"
                        >
                    </div>
                    <div id="create-result"></div>
                    <div class="flex gap-3 justify-end">
                        <button 
                            type="button"
                            onclick="document.getElementById('create-modal').classList.add('hidden')"
                            class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded"
                        >Cancel</button>
                        <button 
                            type="submit"
                            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 rounded"
                        >Create</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Refresh collections list when triggered via HX-Trigger header
        document.body.addEventListener('refreshList', function() {
            htmx.trigger('#collections-list', 'load');
        });
        
        // Refresh collections list when triggered
        document.getElementById('collections-list').addEventListener('refresh', function() {
            htmx.trigger(this, 'load');
        });
        
        // Ingestion progress indicator
        function showIngestProgress() {
            document.querySelectorAll('.ingest-btn').forEach(btn => {
                btn.disabled = true;
                btn.classList.add('opacity-50');
            });
            const result = document.getElementById('ingest-result');
            if (result) {
                result.innerHTML = '<div class="text-yellow-400 text-sm animate-pulse">⏳ Ingesting documents (GPU-accelerated)... This may take a moment.</div>';
            }
        }
        
        function hideIngestProgress() {
            document.querySelectorAll('.ingest-btn').forEach(btn => {
                btn.disabled = false;
                btn.classList.remove('opacity-50');
            });
        }
    </script>
</body>
</html>
