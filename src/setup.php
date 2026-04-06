<?php
declare(strict_types=1);

// CLI-only setup script
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

function out(string $message): void {
    echo $message . PHP_EOL;
}

$dbDir = __DIR__ . '/data';
$dbFile = $dbDir . '/db.sqlite';

if (!is_dir($dbDir)) {
    if (!mkdir($dbDir, 0777, true) && !is_dir($dbDir)) {
        fwrite(STDERR, "Failed to create data directory: {$dbDir}\n");
        exit(1);
    }
}

out('Connecting to SQLite...');
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');

out('Creating tables...');

$db->exec("
    CREATE TABLE IF NOT EXISTS users (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        oidc_sub TEXT NOT NULL UNIQUE,
        username TEXT NOT NULL UNIQUE,
        email TEXT,
        IsAdmin INTEGER NOT NULL DEFAULT 0,
        CreatedAt TEXT DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt TEXT DEFAULT CURRENT_TIMESTAMP
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS apps (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        ContainerName TEXT NOT NULL UNIQUE,
        ContainerID TEXT UNIQUE,
        Image TEXT DEFAULT '',
        Version TEXT DEFAULT 'latest',
        Status TEXT DEFAULT 'unknown',
        Comment TEXT DEFAULT '',
        Port TEXT DEFAULT '',
        Url TEXT DEFAULT '',
        LastPingStatus INTEGER DEFAULT NULL,
        LastPingTime TEXT DEFAULT NULL,
        CreatedAt TEXT DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt TEXT DEFAULT CURRENT_TIMESTAMP
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS container_permissions (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        UserID INTEGER NOT NULL,
        ContainerID INTEGER NOT NULL,
        CanView INTEGER NOT NULL DEFAULT 1,
        CanStart INTEGER NOT NULL DEFAULT 0,
        CanStop INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (UserID) REFERENCES users(ID) ON DELETE CASCADE,
        FOREIGN KEY (ContainerID) REFERENCES apps(ID) ON DELETE CASCADE,
        UNIQUE(UserID, ContainerID)
    )
");

out('Creating indexes...');

$db->exec("CREATE INDEX IF NOT EXISTS idx_container_permissions_user ON container_permissions(UserID)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_container_permissions_container ON container_permissions(ContainerID)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_apps_container_name ON apps(ContainerName)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_apps_status ON apps(Status)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_users_oidc_sub ON users(oidc_sub)");

out('Setup complete.');