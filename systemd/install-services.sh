#!/bin/bash
# EmberCortex - Install systemd services
# Run with: sudo ./install-services.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EMBERCORTEX_DIR="$(dirname "$SCRIPT_DIR")"
USER_HOME=$(eval echo ~${SUDO_USER:-$USER})

echo "=== EmberCortex Service Installer ==="
echo "Project directory: $EMBERCORTEX_DIR"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "Please run as root (sudo ./install-services.sh)"
    exit 1
fi

# Install llama-server service
echo "[1/7] Installing llama-server.service..."
cp "$SCRIPT_DIR/llama-server.service" /etc/systemd/system/
chmod 644 /etc/systemd/system/llama-server.service
chmod 644 "$SCRIPT_DIR/llama-server.conf"

# Install embed-server service
echo "[2/7] Installing embed-server.service..."
cp "$SCRIPT_DIR/embed-server.service" /etc/systemd/system/
chmod 644 /etc/systemd/system/embed-server.service
chmod 644 "$SCRIPT_DIR/embed-server.conf"

# Install rag-server service
echo "[3/7] Installing rag-server.service..."
cp "$SCRIPT_DIR/rag-server.service" /etc/systemd/system/
chmod 644 /etc/systemd/system/rag-server.service
chmod 644 "$SCRIPT_DIR/rag-server.conf"

# Reload systemd
echo "[4/7] Reloading systemd daemon..."
systemctl daemon-reload

# Enable services
echo "[5/7] Enabling services..."
systemctl enable llama-server.service
systemctl enable embed-server.service
systemctl enable rag-server.service

echo "[6/7] Done with systemd setup!"
echo ""

# Check for Python venv
VENV_PATH="$USER_HOME/embercortex-env"
echo "[7/7] Checking Python environment..."
if [ ! -d "$VENV_PATH" ]; then
    echo ""
    echo "WARNING: Python virtual environment not found at $VENV_PATH"
    echo "Create it with:"
    echo "  python3 -m venv $VENV_PATH"
    echo "  source $VENV_PATH/bin/activate"
    echo "  pip install -r $EMBERCORTEX_DIR/rag/requirements.txt"
    echo ""
fi

echo ""
echo "=== Services Installed ==="
echo ""
echo "1. llama-server - LLM inference (llama.cpp)"
echo "   Config: $SCRIPT_DIR/llama-server.conf"
echo ""
echo "2. rag-server - RAG queries (ChromaDB + LlamaIndex)"
echo "   Config: $SCRIPT_DIR/rag-server.conf"
echo ""
echo "=== Quick Commands ==="
echo ""
echo "  # LLM Server"
echo "  sudo systemctl start llama-server"
echo "  sudo systemctl status llama-server"
echo "  journalctl -u llama-server -f"
echo ""
echo "  # RAG Server"
echo "  sudo systemctl start rag-server"
echo "  sudo systemctl status rag-server"
echo "  journalctl -u rag-server -f"
echo ""
echo "  # Start both"
echo "  sudo systemctl start llama-server rag-server"
