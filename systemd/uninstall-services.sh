#!/bin/bash
# EmberCortex - Uninstall Script
# Removes systemd services and optionally cleans up data

set -e

echo "========================================"
echo "EmberCortex Uninstaller"
echo "========================================"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "Please run as root (sudo ./uninstall-services.sh)"
    exit 1
fi

# Ask for confirmation
read -p "This will stop and remove EmberCortex systemd services. Continue? (y/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Aborted."
    exit 0
fi

echo ""
echo "[1/5] Stopping services..."
systemctl stop llama-server 2>/dev/null || true
systemctl stop embed-server 2>/dev/null || true
systemctl stop rag-server 2>/dev/null || true

echo "[2/5] Disabling services..."
systemctl disable llama-server 2>/dev/null || true
systemctl disable embed-server 2>/dev/null || true
systemctl disable rag-server 2>/dev/null || true

echo "[3/5] Removing service files..."
rm -f /etc/systemd/system/llama-server.service
rm -f /etc/systemd/system/llama-server.conf
rm -f /etc/systemd/system/embed-server.service
rm -f /etc/systemd/system/embed-server.conf
rm -f /etc/systemd/system/rag-server.service
rm -f /etc/systemd/system/rag-server.conf

echo "[4/5] Reloading systemd daemon..."
systemctl daemon-reload

echo "[5/5] Done!"
echo ""
echo "Systemd services have been removed."
echo ""

# Ask about data cleanup
read -p "Remove NGINX site configuration? (y/N) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    rm -f /etc/nginx/sites-enabled/embercortex
    rm -f /etc/nginx/sites-available/embercortex
    systemctl reload nginx 2>/dev/null || true
    echo "NGINX configuration removed."
fi

echo ""
echo "========================================"
echo "Uninstall complete!"
echo "========================================"
echo ""
echo "The following items were NOT removed (manual cleanup if needed):"
echo "  - EmberCortex source code: $HOME/EmberCortex"
echo "  - Python virtual environment: $HOME/embercortex-env"
echo "  - ChromaDB data: $HOME/EmberCortex/rag/data"
echo "  - SQLite database: $HOME/EmberCortex/data/*.db"
echo "  - LLM models: $HOME/llm_models"
echo ""
echo "To fully remove, run:"
echo "  rm -rf ~/EmberCortex ~/embercortex-env"
echo ""
