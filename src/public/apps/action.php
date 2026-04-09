<?php
// Messy, yes

require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/db.php';
require_once '../../includes/docker.php';
require_once '../../includes/log.php';
require_once 'templates/list.php';

$log = new logger();

$db = get_db();
$docker = new Docker();

function respond_error($msg) {
    if (isset($_SERVER['HTTP_HX_REQUEST'])) {
        htmx_error($msg);
    } else {
        json_error($msg);
    }
    exit();
}

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? null) : ($_GET['action'] ?? null);
$name = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['name'] ?? null) : ($_GET['name'] ?? null);

$user_id = $_SESSION['user_id'] ?? null;

// literaly the only action that doesnt need a container name
if ($action === 'table') {
    $table = new clist();
    $table->render();
    exit();
}

if (empty($name) || !preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $name)) {
    respond_error("Invalid container name");
}

switch ($action) {
    case 'logs':
        if (!check_container_permission($db, $user_id, $name, 'view')) {
            respond_error("You do not have permission to view logs for this container");
        }
        $lines = isset($_GET['lines']) ? intval($_GET['lines']) : 30;
        $lines = max(1, min($lines, 500));
        $result = $docker->logs($name, $lines);
        $output = $result['output'];
        header('Content-Type: text/plain; charset=UTF-8');
        echo $output;
        exit();

    case 'status':
        if (!check_container_permission($db, $user_id, $name, 'view')) {
            respond_error("You do not have permission to view logs for this container");
        }
        $result = $docker->status($name);
        $output = $result['output'];
        $response = "<div class='status-badge status-" . $output . "'>" . $output . "</div>";
        echo $response;
        exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error("POST only mate");
}

if (empty($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    if (!$user_id || !check_container_permission($db, $user_id, $name, $action)) {
        respond_error("You do not have permission to perform this action on the container");
    }
}

$output = '';
$success = false;

switch ($action) {
    case 'start':
        $result = $docker->start($name);
        $output = $result['output'];
        $success = $result['success'];
        if ($success) {
            $log->logevent("container.start", "started container", "info", $name);
        }
        break;

    case 'stop':
        $result = $docker->stop($name);
        $output = $result['output'];
        $success = $result['success'];
        if ($success) {
            $log->logevent("container.stop", "stopped container", "info", $name);
        }
        break;

    default:
        respond_error("Invalid action");
}

if (isset($_SERVER['HTTP_HX_REQUEST'])) {
    if ($success) {
        echo '<div class="pico-background-green-100 pico-color-green-900"
     style="padding: 0.75rem 1rem; border-radius: 8px; margin-top: 1rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
    <i class="fa fa-check-circle"></i>
    <span>Container action completed successfully.</span>
</div>
';
    } else {
        htmx_error("Error: " . htmlspecialchars($output));
    }
    exit();
} else {
    if ($success) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Container action completed successfully.']);
    } else {
        json_error("Error: " . $output);
    }
    exit();
}

?>