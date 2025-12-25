#!/usr/bin/env bash
#
# Docka Cleanup Script
# Stops all containers and removes build directories
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="${SCRIPT_DIR}/builds"

echo "🧹 Docka Cleanup"
echo "================"

# Stop all docka containers (those with our UUID-like naming pattern)
echo "Stopping containers..."
docker ps -a --filter "name=[a-zA-Z0-9_-]{12,}" --format "{{.Names}}" 2>/dev/null | while read -r name; do
    if [[ ${#name} -ge 12 && ${#name} -le 20 ]]; then
        echo "  Stopping: $name"
        docker rm -fv "$name" 2>/dev/null || true
    fi
done

# Stop any compose projects in builds directory
if [[ -d "$BUILD_DIR" ]]; then
    for dir in "$BUILD_DIR"/*/; do
        if [[ -d "$dir" ]]; then
            sid=$(basename "$dir")
            if [[ -f "$dir/meta.json" ]]; then
                mode=$(jq -r '.mode // "single"' "$dir/meta.json" 2>/dev/null || echo "single")
                if [[ "$mode" == "compose" ]]; then
                    echo "  Stopping compose project: $sid"
                    docker compose -p "$sid" down -v --remove-orphans 2>/dev/null || true
                fi
            fi
        fi
    done
fi

# Clean build directories
echo "Cleaning build directories..."
if [[ -d "$BUILD_DIR" ]]; then
    find "$BUILD_DIR" -mindepth 1 -maxdepth 1 -type d ! -name "sessions" ! -name ".ratelimit" -exec rm -rf {} \;
    echo "  Removed build directories"
fi

# Clean session flags
if [[ -d "$BUILD_DIR/sessions" ]]; then
    find "$BUILD_DIR/sessions" -name "*.flag" -delete 2>/dev/null || true
    echo "  Cleaned session flags"
fi

# Clean rate limit data
if [[ -d "$BUILD_DIR/.ratelimit" ]]; then
    rm -rf "$BUILD_DIR/.ratelimit"/*
    echo "  Cleaned rate limit data"
fi

# Prune unused Docker resources
echo "Pruning Docker resources..."
docker system prune -f --volumes 2>/dev/null || true

echo ""
echo "✅ Cleanup complete"
