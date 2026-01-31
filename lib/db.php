<?php
/**
 * SQLite Database Helper
 */

class Database {
    private static ?PDO $instance = null;
    private static string $dbPath;
    
    public static function init(string $dbPath): void {
        self::$dbPath = $dbPath;
    }
    
    public static function get(): PDO {
        if (self::$instance === null) {
            self::$instance = new PDO('sqlite:' . self::$dbPath);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::migrate();
        }
        return self::$instance;
    }
    
    private static function migrate(): void {
        $db = self::$instance;
        
        // Chat history table
        $db->exec("
            CREATE TABLE IF NOT EXISTS chat_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                role TEXT NOT NULL,
                content TEXT NOT NULL,
                collection TEXT,
                model TEXT,
                response_time REAL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Add response_time column if it doesn't exist (migration for existing DBs)
        try {
            $db->exec("ALTER TABLE chat_history ADD COLUMN response_time REAL");
        } catch (PDOException $e) {
            // Column already exists, ignore
        }
        
        // Settings table
        $db->exec("
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Collections cache (mirrors what's in ChromaDB)
        $db->exec("
            CREATE TABLE IF NOT EXISTS collections (
                name TEXT PRIMARY KEY,
                description TEXT,
                document_count INTEGER DEFAULT 0,
                last_synced DATETIME
            )
        ");
        
        // Create indexes
        $db->exec("CREATE INDEX IF NOT EXISTS idx_chat_session ON chat_history(session_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_chat_created ON chat_history(created_at)");
    }
    
    public static function getSetting(string $key, mixed $default = null): mixed {
        $stmt = self::get()->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? json_decode($row['value'], true) : $default;
    }
    
    public static function setSetting(string $key, mixed $value): void {
        $stmt = self::get()->prepare("
            INSERT INTO settings (key, value, updated_at) 
            VALUES (?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(key) DO UPDATE SET value = ?, updated_at = CURRENT_TIMESTAMP
        ");
        $json = json_encode($value);
        $stmt->execute([$key, $json, $json]);
    }
    
    public static function saveChatMessage(string $sessionId, string $role, string $content, ?string $collection = null, ?string $model = null, ?float $responseTime = null): int {
        $stmt = self::get()->prepare("
            INSERT INTO chat_history (session_id, role, content, collection, model, response_time)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$sessionId, $role, $content, $collection, $model, $responseTime]);
        return (int) self::get()->lastInsertId();
    }
    
    public static function getChatHistory(string $sessionId, int $limit = 50): array {
        $stmt = self::get()->prepare("
            SELECT * FROM chat_history 
            WHERE session_id = ? 
            ORDER BY created_at ASC 
            LIMIT ?
        ");
        $stmt->execute([$sessionId, $limit]);
        return $stmt->fetchAll();
    }
    
    public static function getRecentSessions(int $limit = 20): array {
        $stmt = self::get()->prepare("
            SELECT session_id, MIN(created_at) as started, MAX(created_at) as last_message,
                   COUNT(*) as message_count,
                   (SELECT content FROM chat_history h2 WHERE h2.session_id = chat_history.session_id AND role = 'user' ORDER BY created_at ASC LIMIT 1) as first_message
            FROM chat_history
            GROUP BY session_id
            ORDER BY last_message DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
    
    public static function deleteSession(string $sessionId): void {
        $stmt = self::get()->prepare("DELETE FROM chat_history WHERE session_id = ?");
        $stmt->execute([$sessionId]);
    }
}
