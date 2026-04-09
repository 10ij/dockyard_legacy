<?php
require_once '../../includes/auth.php';
require_once '../../includes/db.php';

$db = get_db();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Location: ../users.php?error=invalid_method');
	exit;
}

$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

if ($userId <= 0) {
	header('Location: ../users.php?error=invalid_user');
	exit;
}

if ($userId === $currentUserId) {
	header('Location: ../users.php?error=cannot_delete_self');
	exit;
}

try {
	$stmt = $db->prepare('DELETE FROM users WHERE ID = :id');
	$stmt->bindValue(':id', $userId, PDO::PARAM_INT);
	$stmt->execute();

	if ($stmt->rowCount() > 0) {
		header('Location: ../users.php?success=user_deleted');
		exit;
	}

	header('Location: ../users.php?error=user_not_found');
	exit;
} catch (PDOException $e) {
	error_log('User delete failed: ' . $e->getMessage());
	header('Location: ../users.php?error=delete_failed');
	exit;
}

