<?php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../private/config.php';

$scriptPath = dirname(__DIR__) . '/private/manage_containers.sh';
if (!file_exists($scriptPath)) {
    die("Error: manage_containers.sh not found at: $scriptPath");
}

$db = get_db();
$log = new Logger();

$updated_containers = array();
$container_statuses = array();
$docker_container_ids = array(); 

$output = shell_exec("bash " . escapeshellarg($scriptPath) . " list 2>&1");

if ($output === null || $output === false) {
    die("Error: Failed to execute manage_containers.sh list command");
}

// Parse the output - format is: name,status,image,ports (one per line)
$lines = explode("\n", trim($output));
$containers = array();

foreach ($lines as $line) {
    if (empty($line)) continue;
    
    $parts = explode(',', $line);
    if (count($parts) < 3) continue;
    
    // Parse container info from bash script output
    $container = array(
        'name' => trim($parts[0]),
        'status' => trim($parts[1]),
        'image' => trim($parts[2]),
        'ports' => isset($parts[3]) ? trim($parts[3]) : ''
    );
    
    $containers[] = $container;
}

if (empty($containers)) {
    echo "No containers found or error parsing output.<br>";
    exit(0);
}

// Process each container from bash script output
foreach ($containers as $container) {
    $container_name = $container['name'];
    $image_full = $container['image'];
    $image_parts = explode(':', $image_full);
    $image_name = $image_parts[0];
    $version = isset($image_parts[1]) ? $image_parts[1] : 'latest';
    
    // Parse status - manage_containers.sh returns format like "Up 2 hours" or "Exited (0) 5 minutes ago"
    $status_raw = $container['status'];
    if (stripos($status_raw, 'Up') === 0) {
        $status = 'running';
    } elseif (stripos($status_raw, 'Exited') === 0) {
        $status = 'exited';
    } elseif (stripos($status_raw, 'Created') === 0) {
        $status = 'created';
    } elseif (stripos($status_raw, 'Restarting') === 0) {
        $status = 'restarting';
    } elseif (stripos($status_raw, 'Paused') === 0) {
        $status = 'paused';
    } else {
        $status = 'unknown';
    }
    
    $container_id = null;
    
    // Parse ports
    $port = $container['ports'];
    
    // Check if this container already exists in the database (by ContainerName since we don't have ID)
    $stmt = $db->prepare('SELECT ID, Url, ContainerID FROM apps WHERE ContainerName = :containerName');
    $stmt->bindParam(':containerName', $container_name, PDO::PARAM_STR);
    $stmt->execute();
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Update existing container record
        try {
            // Use database transaction for consistency
            $db->beginTransaction();
            
            $stmt = $db->prepare('UPDATE apps SET 
                Image = :image, 
                Version = :version, 
                Status = :status,
                Port = :port,
                UpdatedAt = CURRENT_TIMESTAMP
                WHERE ID = :id');
                
            $stmt->bindParam(':image', $image_name, PDO::PARAM_STR);
            $stmt->bindParam(':version', $version, PDO::PARAM_STR);
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            $stmt->bindParam(':port', $port, PDO::PARAM_STR);
            $stmt->bindParam(':id', $existing['ID'], PDO::PARAM_INT);
            
            $stmt->execute();
            $db->commit();
            
            $updated_containers[] = $container_name;
            $container_statuses[$container_name] = $status;
            
        } catch (PDOException $e) {
            $db->rollBack();
            error_log("Database update error: " . $e->getMessage());
        }
    } else {
        // Add new container to database
        $comment = "Added automatically by cron job on " . date('Y-m-d H:i:s');
        
        try {
            // Use database transaction for consistency
            $db->beginTransaction();
            
            $stmt = $db->prepare('INSERT INTO apps 
                (ContainerName, ContainerID, Image, Version, Status, Comment, Port) 
                VALUES (:containerName, :containerId, :image, :version, :status, :comment, :port)');
                
            $stmt->bindParam(':containerName', $container_name, PDO::PARAM_STR);
            $stmt->bindParam(':containerId', $container_id, PDO::PARAM_STR); // Will be NULL
            $stmt->bindParam(':image', $image_name, PDO::PARAM_STR);
            $stmt->bindParam(':version', $version, PDO::PARAM_STR);
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            $stmt->bindParam(':comment', $comment, PDO::PARAM_STR);
            $stmt->bindParam(':port', $port, PDO::PARAM_STR);
            $stmt->execute();
            
            $db->commit();
            
            $updated_containers[] = $container_name;
            $container_statuses[$container_name] = $status;
            
        } catch (PDOException $e) {
            $db->rollBack();
            error_log("Database insert error: " . $e->getMessage());
        }
    }
}

// Clean up containers that no longer exist in Docker
try {
    $stmt = $db->prepare('SELECT ID, ContainerName FROM apps');
    $stmt->execute();
    $db_containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $current_container_names = array_column($containers, 'name');
    
    foreach ($db_containers as $db_container) {
        if (!in_array($db_container['ContainerName'], $current_container_names)) {
            // Container no longer exists, delete it (will cascade to permissions)
            $deleteStmt = $db->prepare('DELETE FROM apps WHERE ID = :id');
            $deleteStmt->bindParam(':id', $db_container['ID'], PDO::PARAM_INT);
            $deleteStmt->execute();
            error_log("Removed container from database: " . $db_container['ContainerName']);
        }
    }
} catch (PDOException $e) {
    error_log("Container cleanup error: " . $e->getMessage());
}

// Console Friendly Output
echo PHP_EOL . "--- Docker Sync Report ---" . PHP_EOL;
echo "Time:   " . date('Y-m-d H:i:s') . PHP_EOL;
echo "Count:  " . count($updated_containers) . " containers processed" . PHP_EOL;
echo "--------------------------" . PHP_EOL;

foreach ($container_statuses as $name => $status) {
    $displayStatus = str_pad("[$status]", 12); 
    echo "{$displayStatus} {$name}" . PHP_EOL;
}

echo "--------------------------" . PHP_EOL;

$log->purge(LOG_RETENTION_DAYS);

// backup sqlite
$sourceFile = __DIR__ . '/../data/db.sqlite';
$backupFile = __DIR__ . "/../data/dockyard_backup_" . date('Ymd_His') . ".sqlite";

if (file_exists($sourceFile)) {
    if (copy($sourceFile, $backupFile)) {
        error_log("Database backup created: " . $backupFile);
        $log->log("Database backup succeeded: " . $backupFile, 'INFO');
    } else {
        error_log("Database backup failed: Could not write to destination.");
        $log->log("Database backup failed to copy.", 'ERROR');
    }
} else {
    error_log("Database backup failed: Source file not found at " . $sourceFile);
    $log->log("Source database missing.", 'ERROR');
}