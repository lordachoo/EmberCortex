# EmberCortex

A lightweight PHP/NGINX front-end for local LLM and RAG services on NVIDIA DGX Spark.

## Overview

EmberCortex provides a simple, extensible web interface for:
- Chat with local LLMs (via llama.cpp) with Markdown rendering and syntax highlighting
- RAG queries across multiple document collections (via ChromaDB + LlamaIndex)
- GPU-accelerated embeddings via llama.cpp embedding server
- Collection management with web UI (create, view chunks, ingest, query, delete)
- Automatic duplicate detection during re-ingestion
- Query history and settings (SQLite)

---

## 🚀 DGX Spark Quick Start

Get EmberCortex running on your DGX Spark in ~10 minutes.

### Step 1: Install llama.cpp (CUDA-optimized)

```bash
# Install required dependency
sudo apt install -y libcurl4-openssl-dev

# Build llama.cpp with CUDA support (takes ~5 min)
cd ~
rm -rf ~/ggml-org 2>/dev/null
mkdir -p ~/ggml-org
git clone https://github.com/ggml-org/llama.cpp ~/ggml-org/llama.cpp
cd ~/ggml-org/llama.cpp
cmake -B build-cuda -DGGML_CUDA=ON
cmake --build build-cuda -j
```

### Step 2: Download Models

```bash
mkdir -p ~/llm_models
cd ~/llm_models

# GPT-OSS-120B (main LLM - ~70GB, Q4 quantized)
wget https://huggingface.co/ggml-org/gpt-oss-120b-GGUF/resolve/main/gpt-oss-120b-Q4_K_M.gguf

# Nomic Embed (for RAG embeddings - ~300MB)
wget https://huggingface.co/nomic-ai/nomic-embed-text-v1.5-GGUF/resolve/main/nomic-embed-text-v1.5.Q8_0.gguf
```

**Alternative models:**
| Model | Size | Use Case | Download |
|-------|------|----------|----------|
| Llama-3.3-70B-Instruct | ~42GB Q4 | General chat | [HuggingFace](https://huggingface.co/bartowski/Llama-3.3-70B-Instruct-GGUF) |
| Qwen2.5-Coder-32B | ~20GB Q4 | Code generation | [HuggingFace](https://huggingface.co/Qwen/Qwen2.5-Coder-32B-Instruct-GGUF) |
| DeepSeek-R1-Distill-Llama-70B | ~42GB Q4 | Reasoning | [HuggingFace](https://huggingface.co/bartowski/DeepSeek-R1-Distill-Llama-70B-GGUF) |

### Step 3: Install EmberCortex

```bash
# Clone the repo
cd ~
git clone https://github.com/lordachoo/EmberCortex.git
cd EmberCortex

# Install NGINX and PHP
sudo apt update
sudo apt install -y nginx php-fpm php-sqlite3 php-curl php-json php-mbstring

# Set up Python environment for RAG
python3 -m venv ~/embercortex-env
source ~/embercortex-env/bin/activate
pip install -r ~/EmberCortex/rag/requirements.txt
```

### Step 4: Configure Services

```bash
cd ~/EmberCortex/systemd

# Edit configs to point to your models
# llama-server.conf - set MODEL_PATH to your LLM
# embed-server.conf - set MODEL_PATH to nomic-embed model

# Install systemd services
sudo ./install-services.sh
```

### Step 5: Configure NGINX

```bash
# Check PHP-FPM version
ls /var/run/php/  # Note the socket name (e.g., php8.3-fpm.sock)

# Create NGINX config
sudo tee /etc/nginx/sites-available/embercortex << 'EOF'
server {
    listen 80;
    server_name localhost;
    root /home/YOUR_USERNAME/EmberCortex/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;  # Adjust version
    }

    location /api/llm/ { proxy_pass http://127.0.0.1:8080/; proxy_read_timeout 300s; }
    location /api/embed/ { proxy_pass http://127.0.0.1:8081/; }
    location /api/rag/ { proxy_pass http://127.0.0.1:8082/; proxy_read_timeout 300s; }
}
EOF

# Replace YOUR_USERNAME with your actual username
sudo sed -i "s/YOUR_USERNAME/$USER/g" /etc/nginx/sites-available/embercortex

# Enable site
sudo rm -f /etc/nginx/sites-enabled/default
sudo ln -sf /etc/nginx/sites-available/embercortex /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# Set permissions
chmod 755 $HOME
chmod -R 755 $HOME/EmberCortex
mkdir -p $HOME/EmberCortex/data
sudo chown -R $USER:www-data $HOME/EmberCortex/data
chmod 775 $HOME/EmberCortex/data
```

### Step 6: Start Services

```bash
sudo systemctl start llama-server
sudo systemctl start embed-server
sudo systemctl start rag-server

# Check status
sudo systemctl status llama-server embed-server rag-server
```

### Step 7: Open EmberCortex

Open your browser to **http://localhost/** (or your DGX Spark's IP)

🎉 **Done!** You now have a local LLM + RAG system running on your DGX Spark.

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                      NGINX (port 80)                            │
├─────────────────────────────────────────────────────────────────┤
│  /                → PHP-FPM (EmberCortex UI)                    │
│  /collections.php → RAG Collection Management UI                │
│  /api/rag/*       → FastAPI RAG Server (port 8082)              │
│  /api/llm/*       → llama.cpp LLM Server (port 8080)            │
│  /api/embed/*     → llama.cpp Embedding Server (port 8081)      │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                    Backend Services                             │
├─────────────────────────────────────────────────────────────────┤
│  llama-server (8080)  │ LLM inference (GPT-OSS-120B, etc.)      │
│  embed-server (8081)  │ GPU embeddings (nomic-embed-text)       │
│  rag-server   (8082)  │ RAG queries + ChromaDB vector store     │
└─────────────────────────────────────────────────────────────────┘
```

## Tech Stack

| Component | Technology |
|-----------|------------|
| **Frontend** | PHP 8.x, HTMX, TailwindCSS |
| **LLM Server** | llama.cpp (CUDA) |
| **Embedding Server** | llama.cpp with nomic-embed-text-v1.5 (GGUF) |
| **RAG Server** | FastAPI + LlamaIndex |
| **Vector Store** | ChromaDB (persistent) |
| **Database** | SQLite (chat history) |
| **Web Server** | NGINX + PHP-FPM |

## Prerequisites

- NVIDIA DGX Spark with CUDA 13+
- llama.cpp built with CUDA support
- Python 3.10+ with virtual environment
- NGINX and PHP-FPM installed

---

## Quick Install

### 1. Install NGINX and PHP

```bash
# Update package list
sudo apt update

# Install NGINX
sudo apt install -y nginx

# Install PHP 8.x with required extensions
sudo apt install -y php-fpm php-sqlite3 php-curl php-json php-mbstring

# Verify installations
nginx -v
php -v
```

### 2. Configure PHP-FPM

Check which PHP-FPM socket is active:

```bash
ls /var/run/php/
# Look for something like: php8.1-fpm.sock or php8.3-fpm.sock
```

Note the version for the NGINX config below.

### 3. Create NGINX Site Configuration

```bash
sudo vim /etc/nginx/sites-available/embercortex
```

Paste the following (adjust `php8.x-fpm.sock` to match your version):

```nginx
server {
    listen 80;
    server_name localhost;
    
    root $HOME/EmberCortex/public;
    index index.php index.html;
    
    # Logging
    access_log /var/log/nginx/embercortex_access.log;
    error_log /var/log/nginx/embercortex_error.log;
    
    # PHP handling
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;  # Adjust version
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Proxy to llama.cpp LLM server
    location /api/llm/ {
        proxy_pass http://127.0.0.1:8080/;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_read_timeout 300s;  # LLM responses can be slow
    }
    
    # Proxy to embedding server
    location /api/embed/ {
        proxy_pass http://127.0.0.1:8081/;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
    
    # Proxy to RAG server (FastAPI)
    location /api/rag/ {
        proxy_pass http://127.0.0.1:8082/;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_read_timeout 300s;
    }
    
    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }
    
    location ~ /(config|data|lib)/ {
        deny all;
    }
}
```

### 4. Enable the Site

```bash
# Remove default site (optional)
sudo rm /etc/nginx/sites-enabled/default

# Enable EmberCortex
sudo ln -s /etc/nginx/sites-available/embercortex /etc/nginx/sites-enabled/

# Test configuration
sudo nginx -t

# Reload NGINX
sudo systemctl reload nginx
```

### 5. Set Permissions

```bash
# Ensure NGINX can read the project
sudo usermod -aG $USER www-data

# Set directory permissions
chmod 755 $HOME
chmod -R 755 $HOME/EmberCortex

# Create data directory for SQLite (writable by PHP)
mkdir -p $HOME/EmberCortex/data
chmod 775 $HOME/EmberCortex/data
sudo chown $USER:www-data $HOME/EmberCortex/data
```

### 6. Create Project Structure

```bash
cd $HOME/EmberCortex

# Create directories
mkdir -p public config lib data

# Create placeholder index
echo '<?php phpinfo();' > public/index.php
```

### 7. Test Installation

```bash
# Restart services
sudo systemctl restart php8.3-fpm  # Adjust version
sudo systemctl restart nginx

# Test in browser
curl http://localhost/
```

You should see the PHP info page.

---

## Project Structure

```
EmberCortex/
├── README.md
├── public/              # Web root (NGINX serves from here)
│   ├── index.php        # Main entry / chat interface
│   ├── collections.php  # Manage RAG collections
│   ├── ingest.php       # Document ingestion
│   ├── settings.php     # Configuration UI
│   └── assets/          # CSS, JS, images
├── config/
│   └── config.php       # App configuration
├── lib/
│   ├── db.php           # SQLite helpers
│   ├── api.php          # API client for LLM/RAG services
│   └── utils.php        # Shared utilities
├── data/
│   └── embercortex.db   # SQLite database (auto-created)
├── systemd/             # Systemd service files
│   ├── llama-server.service    # Service unit file
│   ├── llama-server.conf       # Configuration (model, ports, etc.)
│   └── install-services.sh     # Installation script
└── templates/           # Reusable HTML fragments (optional)
```

---

## Configuration

Edit `config/config.php`:

```php
<?php
return [
    // Backend service URLs
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
];
```

---

## Troubleshooting

### NGINX won't start
```bash
sudo nginx -t                    # Check config syntax
sudo tail -f /var/log/nginx/error.log
```

### PHP errors
```bash
sudo tail -f /var/log/php8.3-fpm.log  # Adjust version
```

### Permission denied on SQLite
```bash
sudo chown -R $USER:www-data $HOME/EmberCortex/data
chmod 775 $HOME/EmberCortex/data
```

### Check service status
```bash
sudo systemctl status nginx
sudo systemctl status php8.3-fpm
```

---

## llama.cpp Server Setup (Systemd)

EmberCortex includes systemd service files for running llama.cpp as a managed service.

### Configuration File

Edit `systemd/llama-server.conf` to configure your model and settings:

```bash
# Key settings in llama-server.conf:

# Path to your model (GGUF format)
MODEL_PATH=$HOME/llm_models/Llama-3.3-70B-Instruct-Q4_K_M.gguf

# Server port (must match config/config.php)
PORT=8080

# Context length (adjust based on VRAM)
CONTEXT_LENGTH=16384

# GPU layers (99 = all)
GPU_LAYERS=99
```

### Install the Service

```bash
cd $HOME/EmberCortex/systemd

# Run the installer
sudo ./install-services.sh
```

### Manage the Service

```bash
# Start the server
sudo systemctl start llama-server

# Stop the server
sudo systemctl stop llama-server

# Restart (after config changes)
sudo systemctl restart llama-server

# Check status
sudo systemctl status llama-server

# View logs
journalctl -u llama-server -f

# Enable auto-start on boot
sudo systemctl enable llama-server
```

### Switching Models

1. Edit the config file:
   ```bash
   vim $HOME/EmberCortex/systemd/llama-server.conf
   ```

2. Change `MODEL_PATH` to your new model:
   ```bash
   MODEL_PATH=$HOME/llm_models/your-new-model.gguf
   ```

3. Restart the service:
   ```bash
   sudo systemctl restart llama-server
   ```

### Available Configuration Options

| Variable | Description | Default |
|----------|-------------|---------|
| `LLAMA_CPP_DIR` | Path to llama.cpp build | `$HOME/ggml-org/llama.cpp/build-cuda` |
| `MODEL_PATH` | Path to GGUF model file | (required) |
| `HOST` | Listen address | `0.0.0.0` |
| `PORT` | Listen port | `8080` |
| `CONTEXT_LENGTH` | Context window size | `16384` |
| `GPU_LAYERS` | Layers to offload to GPU | `99` |
| `THREADS` | CPU threads | `16` |
| `BATCH_SIZE` | Batch size | `2048` |
| `NO_MMAP` | Disable mmap | `true` |
| `FLASH_ATTN` | Enable flash attention | `false` |
| `EXTRA_FLAGS` | Additional llama-server flags | (empty) |

---

## RAG Server Setup

The RAG server provides document retrieval and augmented generation using ChromaDB and LlamaIndex.

### 1. Create Python Environment

```bash
# Create virtual environment
python3 -m venv ~/embercortex-env

# Activate it
source ~/embercortex-env/bin/activate

# Install dependencies
pip install -r ~/EmberCortex/rag/requirements.txt
```

### 2. Install the RAG Service

```bash
cd ~/EmberCortex/systemd
sudo ./install-services.sh
```

### 3. Configure RAG Server

Edit `systemd/rag-server.conf`:

```bash
# Key settings:
RAG_PORT=8082                              # Must match config/config.php
LLM_API_BASE=http://127.0.0.1:8080/v1      # Points to llama.cpp
EMBED_MODEL=nomic-ai/nomic-embed-text-v1.5 # Embedding model (auto-downloaded)
CHUNK_SIZE=512                             # Document chunk size
```

### 4. Start the RAG Server

```bash
sudo systemctl start rag-server
sudo systemctl status rag-server
```

### 5. Manage Collections (Web UI)

Navigate to **http://localhost/collections.php** to:
- View all collections and their document counts
- Create new collections
- Ingest documents from server directories
- Test queries against collections
- Delete collections

### 6. Manage Collections (CLI)

Alternatively, use the command-line tool:

```bash
# Activate the environment
source ~/embercortex-env/bin/activate
cd ~/EmberCortex/rag

# Ingest a codebase
python ingest.py ingest ~/projects/my-project codebase -d "My project source code"

# Ingest documentation
python ingest.py ingest ~/docs/nvidia nvidia_docs -d "NVIDIA documentation"

# List collections
python ingest.py list

# Delete a collection
python ingest.py delete old_collection
```

### 7. Manage Collections (curl)

```bash
# List collections
curl http://localhost:8082/collections

# Create a collection
curl -X POST "http://localhost:8082/collections/my_collection?description=My%20docs"

# Ingest a directory
curl -X POST http://localhost:8082/ingest/directory \
  -H "Content-Type: application/json" \
  -d '{"collection": "my_collection", "directory": "$HOME/my-project"}'

# Query a collection
curl -X POST http://localhost:8082/query \
  -H "Content-Type: application/json" \
  -d '{"query": "How does authentication work?", "collection": "my_collection"}'

# Delete a collection
curl -X DELETE http://localhost:8082/collections/my_collection
```

### RAG Server Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/collections` | GET | List all collections |
| `/collections/{name}` | POST | Create a collection |
| `/collections/{name}` | DELETE | Delete a collection |
| `/query` | POST | Query with RAG |
| `/ingest/directory` | POST | Ingest from server directory |
| `/ingest/files/{collection}` | POST | Upload and ingest files |

---

## Project Structure (Complete)

```
EmberCortex/
├── README.md
├── public/                  # Web root (NGINX serves from here)
│   ├── index.php            # Main chat interface
│   └── collections.php      # RAG collection management UI
├── config/
│   └── config.php           # PHP app configuration
├── lib/
│   ├── db.php               # SQLite helpers
│   └── api.php              # API client for LLM/RAG
├── data/
│   └── embercortex.db       # SQLite database (auto-created)
├── rag/                     # RAG server (Python)
│   ├── requirements.txt     # Python dependencies
│   ├── config.py            # RAG configuration
│   ├── rag_server.py        # FastAPI RAG server
│   ├── ingest.py            # Document ingestion CLI
│   └── data/
│       └── chroma_db/       # ChromaDB storage
└── systemd/                 # Systemd services
    ├── llama-server.service # LLM server unit file
    ├── llama-server.conf    # LLM server config
    ├── embed-server.service # Embedding server unit file
    ├── embed-server.conf    # Embedding server config
    ├── rag-server.service   # RAG server unit file
    ├── rag-server.conf      # RAG server config
    └── install-services.sh  # Installation script
```

---

## IDE Integration (Continue.dev)

Your llama.cpp server exposes an OpenAI-compatible API, making it easy to use with code editors.

### Install Continue Extension

1. **VS Code**: Install "Continue" from the Extensions marketplace
2. **JetBrains**: Install "Continue" from the Plugins marketplace

### Configure Continue

Create or edit `~/.continue/config.json`:

```json
{
  "models": [
    {
      "title": "EmberCortex Local",
      "provider": "openai",
      "model": "gpt-oss-120b",
      "apiBase": "http://localhost:8080/v1",
      "apiKey": "not-needed"
    }
  ],
  "tabAutocompleteModel": {
    "title": "Local Autocomplete",
    "provider": "openai",
    "model": "gpt-oss-120b",
    "apiBase": "http://localhost:8080/v1",
    "apiKey": "not-needed"
  },
  "contextProviders": [
    { "name": "code" },
    { "name": "docs" },
    { "name": "diff" },
    { "name": "terminal" },
    { "name": "problems" },
    { "name": "folder" }
  ]
}
```

### Using Continue

| Feature | How to Use |
|---------|------------|
| **Chat** | Click Continue icon in sidebar or `Ctrl+L` / `Cmd+L` |
| **Edit Code** | Highlight code, press `Ctrl+I` / `Cmd+I`, describe changes |
| **Autocomplete** | Just type - suggestions appear automatically |
| **Explain** | Highlight code, type `/explain` in chat |
| **Add Docs** | Highlight code, type `/comment` |
| **Fix Error** | Click on error, type `/fix` |

### Other IDE Options

| Tool | Configuration |
|------|---------------|
| **Cursor** | Settings → Models → OpenAI API Base → `http://localhost:8080/v1` |
| **Aider** | `aider --openai-api-base http://localhost:8080/v1 --model gpt-oss-120b` |
| **Any OpenAI Client** | Set `api_base` to `http://localhost:8080/v1` |

### Recommended Models for Code

For best code editing results, consider using coder-tuned models:

| Model | Size | Best For |
|-------|------|----------|
| Qwen2.5-Coder-32B | ~20GB Q4 | Code generation, editing |
| DeepSeek-Coder-V2 | ~16GB Q4 | Fast code completion |
| CodeLlama-34B | ~20GB Q4 | Code understanding |

Download and update `systemd/llama-server.conf` to switch models.

---

## Embedding Server Setup

The embedding server provides GPU-accelerated embeddings using llama.cpp with a GGUF embedding model.

### 1. Download Embedding Model

```bash
cd ~/llm_models
wget https://huggingface.co/nomic-ai/nomic-embed-text-v1.5-GGUF/resolve/main/nomic-embed-text-v1.5.Q8_0.gguf
```

### 2. Configure Embedding Server

Edit `systemd/embed-server.conf`:

```bash
# Key settings:
MODEL_PATH=$HOME/llm_models/nomic-embed-text-v1.5.Q8_0.gguf
PORT=8081
GPU_LAYERS=99
BATCH_SIZE=8192  # Must be >= max tokens per chunk
```

### 3. Install and Start

```bash
cd ~/EmberCortex/systemd
sudo ./install-services.sh

# Start the embedding server
sudo systemctl start embed-server
sudo systemctl status embed-server
```

### 4. Test Embeddings

```bash
curl -s http://localhost:8081/v1/embeddings \
  -H "Content-Type: application/json" \
  -d '{"input": "test embedding", "model": "nomic"}' | head -c 200
```

---

## Quick Start (All Services)

```bash
# 1. Start all services
sudo systemctl start llama-server
sudo systemctl start embed-server
sudo systemctl start rag-server

# 2. Check status
sudo systemctl status llama-server embed-server rag-server

# 3. Open the web UI
# http://localhost/

# 4. Create a RAG collection (Web UI)
# Go to http://localhost/collections.php
# Click "+ New Collection"
# Enter directory path and click "Ingest Directory"

# 5. Or ingest via CLI
curl -X POST http://localhost:8082/ingest/directory \
  -H "Content-Type: application/json" \
  -d '{"collection": "my_code", "directory": "$HOME/my-project"}'

# 6. Chat with RAG
# Select your collection from the dropdown in the chat UI
```

---

## RAG Features

### Duplicate Detection

When re-ingesting documents, EmberCortex automatically:
- Computes SHA256 hash of each document (content + file path)
- Skips documents that already exist in the collection
- Reports how many documents were ingested vs skipped

### Supported File Types

The following file extensions are indexed:
- **Code**: `.py`, `.js`, `.ts`, `.jsx`, `.tsx`, `.c`, `.h`, `.cpp`, `.hpp`, `.go`, `.rs`, `.java`, `.rb`, `.lua`, `.php`
- **Docs**: `.md`, `.txt`, `.rst`
- **Config**: `.json`, `.yaml`, `.yml`
- **Web**: `.html`, `.css`
- **Scripts**: `.sh`, `.bash`

### RAG Server Endpoints (Complete)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/health` | GET | Health check |
| `/collections` | GET | List all collections |
| `/collections/{name}` | POST | Create a collection |
| `/collections/{name}` | DELETE | Delete a collection |
| `/collections/{name}/chunks` | GET | View chunks in collection |
| `/collections/{name}/clear` | DELETE | Clear collection (keep metadata) |
| `/query` | POST | Query with RAG |
| `/ingest/directory` | POST | Ingest from server directory |
| `/ingest/files/{collection}` | POST | Upload and ingest files |

---

## Service Management

### View Logs

```bash
# LLM server logs
journalctl -u llama-server -f

# Embedding server logs
journalctl -u embed-server -f

# RAG server logs
journalctl -u rag-server -f
```

### Restart Services

```bash
# After config changes
sudo systemctl restart llama-server
sudo systemctl restart embed-server
sudo systemctl restart rag-server
```

### Enable Auto-Start

```bash
sudo systemctl enable llama-server embed-server rag-server
```

---

## Uninstall

To remove EmberCortex systemd services:

```bash
cd ~/EmberCortex/systemd
sudo ./uninstall-services.sh
```

This will:
- Stop and disable all EmberCortex services
- Remove systemd unit files
- Optionally remove NGINX configuration

To fully remove all data:
```bash
rm -rf ~/EmberCortex ~/embercortex-env
```

---

*EmberCortex - Local AI, Your Way*
