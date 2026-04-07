#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="$(pwd)"
CRON_CMD="docker compose exec dockyard php cron/cron.php"

echo "[1/5] Starting Docker Compose..."
docker compose up -d

echo "[2/5] Waiting for container to be ready..."
sleep 3

echo "[3/5] Running setup script (one-time)..."
docker compose exec dockyard php private/setup.php

echo "[4/5] Installing cron job..."

# check if cron already exists
(crontab -l 2>/dev/null | grep -F "$CRON_CMD") && {
    echo "Cron already exists, skipping."
} || {
    (crontab -l 2>/dev/null; echo "* * * * * $CRON_CMD >> $PROJECT_DIR/cron.log 2>&1") | crontab -
    echo "Cron installed."
}

echo "[5/5] Done."
echo "Dockyard is running."