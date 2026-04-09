<?php
/**
 * Handles internal system logging via SQLite.
 * @package Dockyard
 * @author 10ij
 */

class Logger
{
    private SQLite3 $db;

    public function __construct()
    {
        $dbPath = __DIR__ . '/../data/db.sqlite';
        $this->db = new SQLite3($dbPath);
    }

    private function getSessionValue(string $key): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        if (!isset($_SESSION[$key])) {
            return null;
        }

        return (string)$_SESSION[$key];
    }

    private function getRemoteAddress(): ?string
    {
        return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : null;
    }

    private function getUserAgent(): ?string
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : null;
    }

    /**
     * Record a structured audit event.
     *
     * @param string $action e.g. container.start, container.stop, permissions.update
     * @param string $message short human-readable event summary
     * @param string $level INFO|WARN|ERROR
     * @param string|null $container container name when applicable
     * @param array<string,mixed>|null $metadata extra context serialized as JSON
     * @return bool
     */
    public function logEvent(
        string $action,
        string $message,
        string $level = 'INFO',
        ?string $container = null,
        ?array $metadata = null
    ): bool {
        $stmt = $this->db->prepare('INSERT INTO audit_logs (Level, Action, Message, ContainerName, UserID, Username, OidcSub, IPAddress, UserAgent, MetadataJson)
            VALUES (:level, :action, :message, :container, :user_id, :username, :oidc_sub, :ip_address, :user_agent, :metadata_json)');

        $json = null;
        if ($metadata !== null) {
            $encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES);
            $json = ($encoded === false) ? null : $encoded;
        }

        $userId = $this->getSessionValue('user_id');
        $stmt->bindValue(':level', strtoupper($level), SQLITE3_TEXT);
        $stmt->bindValue(':action', $action, SQLITE3_TEXT);
        $stmt->bindValue(':message', $message, SQLITE3_TEXT);
        $stmt->bindValue(':container', $container, SQLITE3_TEXT);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':username', $this->getSessionValue('username'), SQLITE3_TEXT);
        $stmt->bindValue(':oidc_sub', $this->getSessionValue('oidc_sub'), SQLITE3_TEXT);
        $stmt->bindValue(':ip_address', $this->getRemoteAddress(), SQLITE3_TEXT);
        $stmt->bindValue(':user_agent', $this->getUserAgent(), SQLITE3_TEXT);
        $stmt->bindValue(':metadata_json', $json, SQLITE3_TEXT);

        return $stmt->execute() !== false;
    }

    /**
     * Backwards-compatible helper.
     *
     * @return bool
     */
    public function log(string $message, string $level = 'INFO', ?string $container = null): bool
    {
        return $this->logEvent('system.log', $message, $level, $container, null);
    }

    /** * Retrieves logs and returns them as a JSON string
     * @param int $limit
     * @return string 
     */
    public function getLogsJson(int $limit = 100): string
    {
        $limit = max(1, min($limit, 1000));
        $stmt = $this->db->prepare('SELECT * FROM audit_logs ORDER BY ID DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        
        $result = $stmt->execute();
        $logs = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $logs[] = $row;
        }

        $json = json_encode($logs, JSON_PRETTY_PRINT);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to encode logs to JSON');
        }

        return $json;
    }

    /**
     * Clears logs older than X days
     * @param int $days
     * @return bool
     */
    public function purge(int $days = 7): bool
    {
        $stmt = $this->db->prepare("DELETE FROM audit_logs WHERE CreatedAt <= date('now', :days)");
        $stmt->bindValue(':days', "-$days days", SQLITE3_TEXT);
        return $stmt->execute() !== false;
    }
}