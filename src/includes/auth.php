<?php
/**
 * Authentication and session management for Dockyard.
 * @package Dockyard
 * @author 10ij
 */

/* NOTES:
   - All client facing pages should use this
   - Login.php doesn't use this (for obvious reasons)
   - All admin facing pages must call require_admin()
   - OIDC Flow begins at line 80
   - We derive admin status from OIDC groups
   - CSRF token is generated on login
*/
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'self'");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

require_once __DIR__ . '/../private/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once 'log.php';
$log = new Logger();
require_once 'db.php';
$db = get_db();

use Jumbojett\OpenIDConnectClient;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 1800)) {
    session_unset();
    session_destroy();
    header('Location: /login.php'); 
    exit;
}

/**
 * Upsert??? local user from OIDC identity
 * OIDC is the source of truth
 */
function sync_oidc_user(PDO $db, string $oidcSub, string $username, ?string $email, bool $oidcIsAdmin): array {
    $stmt = $db->prepare('SELECT ID, oidc_sub, username, email, IsAdmin FROM users WHERE oidc_sub = :oidc_sub LIMIT 1');
    $stmt->bindValue(':oidc_sub', $oidcSub, PDO::PARAM_STR);
    $stmt->execute();
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    $isAdminInt = $oidcIsAdmin ? 1 : 0;

    if ($existing) {
        $upd = $db->prepare('
            UPDATE users
            SET username = :username, email = :email, IsAdmin = :is_admin, UpdatedAt = CURRENT_TIMESTAMP
            WHERE ID = :id
        ');
        $upd->bindValue(':username', $username, PDO::PARAM_STR);
        $upd->bindValue(':email', $email, PDO::PARAM_STR);
        $upd->bindValue(':is_admin', $isAdminInt, PDO::PARAM_INT);
        $upd->bindValue(':id', (int)$existing['ID'], PDO::PARAM_INT);
        $upd->execute();

        $existing['username'] = $username;
        $existing['email'] = $email;
        $existing['IsAdmin'] = $isAdminInt;
        return $existing;
    }

    $ins = $db->prepare('
        INSERT INTO users (oidc_sub, username, email, IsAdmin)
        VALUES (:oidc_sub, :username, :email, :is_admin)
    ');
    $ins->bindValue(':oidc_sub', $oidcSub, PDO::PARAM_STR);
    $ins->bindValue(':username', $username, PDO::PARAM_STR);
    $ins->bindValue(':email', $email, PDO::PARAM_STR);
    $ins->bindValue(':is_admin', $isAdminInt, PDO::PARAM_INT);
    $ins->execute();

    return [
        'ID' => (int)$db->lastInsertId(),
        'oidc_sub' => $oidcSub,
        'username' => $username,
        'email' => $email,
        'IsAdmin' => $isAdminInt,
    ];
}

// OIDC flow
if (!isset($_SESSION['authenticated'])) {
    try {
        $oidc = new OpenIDConnectClient(
            OIDC_PROVIDER_URL, 
            OIDC_CLIENT_ID,
            OIDC_CLIENT_SECRET
        );

        $oidc->addScope(['openid', 'profile', 'email', 'groups']);

        $oidc->setRedirectURL(APP_BASE_URL . 'index.php');

        // Jumbojett's lib does the rest here, no need for a callback page or anything
        $oidc->authenticate();

        $userInfo = $oidc->requestUserInfo();

        // Sync OIDC to local user and session
        $oidcSub = (string)($userInfo->sub ?? '');
        if ($oidcSub === '') {
            throw new RuntimeException('OIDC subject (sub) is missing');
        }

        $oidcUsername = $userInfo->preferred_username ?? ($userInfo->name ?? 'User');
        $oidcEmail = $userInfo->email ?? null;

        if (DERIVE_ADMIN_BY_GROUP) {
            $oidcIsAdmin = isset($userInfo->groups) && in_array(OIDC_ADMIN_GROUP, (array)$userInfo->groups, true);
        } else {
            // Check if THIS specific user is already in our DB
            $stmt = $db->prepare('SELECT IsAdmin FROM users WHERE oidc_sub = ?');
            $stmt->execute([$oidcSub]);
            $existingUser = $stmt->fetch();

            if ($existingUser) {
                // If they exist, keep their current admin status
                $oidcIsAdmin = (bool)$existingUser['IsAdmin'];
            } else {
                // If they are new, make them admin only if they are the VERY first user
                $stmt = $db->query('SELECT COUNT(*) FROM users');
                $userCount = (int)$stmt->fetchColumn();
                $oidcIsAdmin = ($userCount === 0);
            }
        }

        $localUser = sync_oidc_user($db, $oidcSub, $oidcUsername, $oidcEmail, $oidcIsAdmin);

        session_regenerate_id(true);

        $_SESSION['authenticated'] = true;
        $_SESSION['oidc_sub'] = $oidcSub;
        $_SESSION['user_id'] = (int)$localUser['ID'];
        $_SESSION['username'] = $localUser['username'];
        $_SESSION['email'] = $localUser['email'];
        if (DERIVE_ADMIN_BY_GROUP) {
            $_SESSION['isAdmin'] = $oidcIsAdmin;
        } else {
            $_SESSION['isAdmin'] = ((int)$localUser['IsAdmin'] === 1);
        }

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    } catch (Exception $e) {
        error_log("OIDC Auth Error: " . $e->getMessage());
        die("Authentication failed. Please try again later.");
    }
}

$_SESSION['LAST_ACTIVITY'] = time();
$isAdmin = isset($_SESSION['isAdmin']) && $_SESSION['isAdmin'] === true;

function require_admin(): void {
    if (empty($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
        header('Location: /index.php?error=unauthorized');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postToken = $_POST['csrf_token'] ?? '';

    if (empty($postToken) || !hash_equals($sessionToken, $postToken)) {
        // Log the failure
        $log->logEvent('security.csrf_failure', 'CSRF token validation failed', 'WARNING', null, ['endpoint' => $_SERVER['PHP_SELF']]);
        http_response_code(403);
        die("Security error: Invalid form submission.");
    }
}

