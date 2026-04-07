<?php
declare(strict_types=1);

// CLI-only setup script
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$lockFile = __DIR__ . '/setup.lock';
$doneFile = __DIR__ . '/setup.done';

// Already completed → exit safely
if (file_exists($doneFile)) {
    out('Setup already completed. Skipping.');
    exit(0);
}

// Acquire lock (atomic)
$lockHandle = fopen($lockFile, 'c');

if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another setup process is running.\n");
    fwrite(STDERR, "Or a previous setup never finished.\n");
    exit(1);
}

// Optional: store PID (useful for debugging)
ftruncate($lockHandle, 0);
fwrite($lockHandle, (string)getmypid());

function out(string $message): void {
    echo $message . PHP_EOL;
}

$dbDir = __DIR__ . '/../data';
$dbFile = $dbDir . '/db.sqlite';

    try {

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

    $db->exec(" 
        CREATE TABLE IF NOT EXISTS audit_logs (
            ID INTEGER PRIMARY KEY AUTOINCREMENT,
            CreatedAt TEXT DEFAULT CURRENT_TIMESTAMP,
            Level TEXT NOT NULL,
            Action TEXT NOT NULL,
            Message TEXT NOT NULL,
            ContainerName TEXT,
            UserID INTEGER,
            Username TEXT,
            OidcSub TEXT,
            IPAddress TEXT,
            UserAgent TEXT,
            MetadataJson TEXT,
            FOREIGN KEY (UserID) REFERENCES users(ID) ON DELETE SET NULL
        )
    ");

    out('Creating indexes...');

    $db->exec("CREATE INDEX IF NOT EXISTS idx_container_permissions_user ON container_permissions(UserID)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_container_permissions_container ON container_permissions(ContainerID)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_apps_container_name ON apps(ContainerName)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_apps_status ON apps(Status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_users_oidc_sub ON users(oidc_sub)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at ON audit_logs(CreatedAt)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_logs_user_id ON audit_logs(UserID)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_logs_action ON audit_logs(Action)");

    out('Setup complete.');

    // Only mark done AFTER everything succeeds
    if (!file_put_contents($doneFile, "completed")) {
        throw new RuntimeException("Failed to write completion marker.");
    }

} catch (Throwable $e) {
    fwrite(STDERR, "Setup failed: " . $e->getMessage() . "\n");
    exit(1);

} finally {
    // Always release lock
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    // Cleanup lock file (optional but tidy)
    @unlink($lockFile);
}