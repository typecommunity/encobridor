<?php
/**
 * Cloaker Pro - Classe Database
 * Gerenciamento de conexão com banco de dados usando PDO
 */

class Database {
    private static $instance = null;
    private $conn = null;
    private $queryCount = 0;
    private $queryTime = 0;
    private $queries = [];
    
    /**
     * Construtor privado (Singleton)
     */
    private function __construct() {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ];
            
            $this->conn = new PDO($dsn, DB_USER, DB_PASS, $options);
            
        } catch (PDOException $e) {
            if (DEBUG_MODE) {
                die('Database connection failed: ' . $e->getMessage());
            } else {
                error_log('Database connection failed: ' . $e->getMessage());
                die('Database connection failed. Please check your configuration.');
            }
        }
    }
    
    /**
     * Obter instância única (Singleton)
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Obter conexão PDO
     */
    public function getConnection() {
        return $this->conn;
    }
    
    /**
     * Alias para getConnection() - retorna PDO
     * @return PDO
     */
    public function getPdo() {
        return $this->conn;
    }
    
    /**
     * Executar query com prepared statements
     */
    public function query($sql, $params = []) {
        $startTime = microtime(true);
        
        try {
            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute($params);
            
            $this->queryCount++;
            $this->queryTime += (microtime(true) - $startTime);
            
            if (DEBUG_MODE) {
                $this->queries[] = [
                    'sql' => $sql,
                    'params' => $params,
                    'time' => microtime(true) - $startTime
                ];
            }
            
            return $stmt;
            
        } catch (PDOException $e) {
            error_log('Database query error: ' . $e->getMessage() . ' | SQL: ' . $sql);
            if (DEBUG_MODE) {
                throw $e;
            }
            return false;
        }
    }
    
    /**
     * SELECT com fetch all
     */
    public function select($table, $where = [], $fields = '*', $order = '', $limit = '', $joins = []) {
        $sql = "SELECT $fields FROM $table";
        $params = [];
        
        // JOINs
        foreach ($joins as $join) {
            $sql .= " " . $join;
        }
        
        // WHERE
        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $field => $value) {
                if (is_array($value)) {
                    // IN clause
                    $placeholders = array_fill(0, count($value), '?');
                    $conditions[] = "$field IN (" . implode(',', $placeholders) . ")";
                    $params = array_merge($params, $value);
                } elseif ($value === null) {
                    $conditions[] = "$field IS NULL";
                } elseif (strpos($field, ' ') !== false) {
                    // Custom operator (e.g., "age >" or "name LIKE")
                    $conditions[] = "$field ?";
                    $params[] = $value;
                } else {
                    $conditions[] = "$field = ?";
                    $params[] = $value;
                }
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        // ORDER BY
        if ($order) {
            $sql .= " ORDER BY $order";
        }
        
        // LIMIT
        if ($limit) {
            $sql .= " LIMIT $limit";
        }
        
        $stmt = $this->query($sql, $params);
        return $stmt ? $stmt->fetchAll() : [];
    }
    
    /**
     * SELECT com fetch one
     */
    public function selectOne($table, $where = [], $fields = '*', $joins = []) {
        $result = $this->select($table, $where, $fields, '', '1', $joins);
        return !empty($result) ? $result[0] : null;
    }
    
    /**
     * INSERT
     */
    public function insert($table, $data) {
        if (empty($data)) {
            return false;
        }
        
        $fields = array_keys($data);
        $values = array_map(function($field) { return ':' . $field; }, $fields);
        
        $sql = "INSERT INTO $table (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")";
        
        $stmt = $this->query($sql, $data);
        
        return $stmt ? $this->conn->lastInsertId() : false;
    }
    
    /**
     * INSERT múltiplos registros
     */
    public function insertBatch($table, $dataArray) {
        if (empty($dataArray)) {
            return false;
        }
        
        $fields = array_keys($dataArray[0]);
        $placeholders = '(' . implode(',', array_fill(0, count($fields), '?')) . ')';
        $values = [];
        
        foreach ($dataArray as $data) {
            foreach ($fields as $field) {
                $values[] = $data[$field] ?? null;
            }
        }
        
        $sql = "INSERT INTO $table (" . implode(', ', $fields) . ") VALUES ";
        $sql .= implode(', ', array_fill(0, count($dataArray), $placeholders));
        
        $stmt = $this->query($sql, $values);
        
        return $stmt !== false;
    }
    
    /**
     * UPDATE
     */
    public function update($table, $data, $where = []) {
        if (empty($data)) {
            return false;
        }
        
        $set = [];
        $params = [];
        
        foreach ($data as $field => $value) {
            $set[] = "$field = ?";
            $params[] = $value;
        }
        
        $sql = "UPDATE $table SET " . implode(', ', $set);
        
        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $field => $value) {
                if (is_array($value)) {
                    $placeholders = array_fill(0, count($value), '?');
                    $conditions[] = "$field IN (" . implode(',', $placeholders) . ")";
                    $params = array_merge($params, $value);
                } else {
                    $conditions[] = "$field = ?";
                    $params[] = $value;
                }
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        $stmt = $this->query($sql, $params);
        
        return $stmt ? $stmt->rowCount() : false;
    }
    
    /**
     * DELETE
     */
    public function delete($table, $where = []) {
        $sql = "DELETE FROM $table";
        $params = [];
        
        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $field => $value) {
                if (is_array($value)) {
                    $placeholders = array_fill(0, count($value), '?');
                    $conditions[] = "$field IN (" . implode(',', $placeholders) . ")";
                    $params = array_merge($params, $value);
                } else {
                    $conditions[] = "$field = ?";
                    $params[] = $value;
                }
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        $stmt = $this->query($sql, $params);
        
        return $stmt ? $stmt->rowCount() : false;
    }
    
    /**
     * COUNT
     */
    public function count($table, $where = []) {
        $result = $this->selectOne($table, $where, 'COUNT(*) as count');
        return $result ? intval($result['count']) : 0;
    }
    
    /**
     * EXISTS
     */
    public function exists($table, $where = []) {
        return $this->count($table, $where) > 0;
    }
    
    /**
     * Incrementar campo
     */
    public function increment($table, $field, $value = 1, $where = []) {
        $sql = "UPDATE $table SET $field = $field + ?";
        $params = [$value];
        
        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $wField => $wValue) {
                $conditions[] = "$wField = ?";
                $params[] = $wValue;
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        $stmt = $this->query($sql, $params);
        
        return $stmt ? $stmt->rowCount() : false;
    }
    
    /**
     * Decrementar campo
     */
    public function decrement($table, $field, $value = 1, $where = []) {
        return $this->increment($table, $field, -$value, $where);
    }
    
    /**
     * Executar query raw
     */
    public function raw($sql, $params = []) {
        return $this->query($sql, $params);
    }
    
    /**
     * Iniciar transação
     */
    public function beginTransaction() {
        return $this->conn->beginTransaction();
    }
    
    /**
     * Confirmar transação
     */
    public function commit() {
        return $this->conn->commit();
    }
    
    /**
     * Reverter transação
     */
    public function rollback() {
        return $this->conn->rollBack();
    }
    
    /**
     * Verificar se está em transação
     */
    public function inTransaction() {
        return $this->conn->inTransaction();
    }
    
    /**
     * Escapar string (para casos especiais)
     */
    public function escape($value) {
        return $this->conn->quote($value);
    }
    
    /**
     * Obter último ID inserido
     */
    public function lastInsertId() {
        return $this->conn->lastInsertId();
    }
    
    /**
     * Obter estatísticas de queries
     */
    public function getStats() {
        return [
            'count' => $this->queryCount,
            'time' => $this->queryTime,
            'queries' => $this->queries
        ];
    }
    
    /**
     * Limpar cache de queries (debug)
     */
    public function clearStats() {
        $this->queryCount = 0;
        $this->queryTime = 0;
        $this->queries = [];
    }
    
    /**
     * Verificar conexão
     */
    public function ping() {
        try {
            $this->conn->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Reconectar se necessário
     */
    public function reconnect() {
        $this->conn = null;
        self::$instance = null;
        return self::getInstance();
    }
    
    /**
     * Destrutor
     */
    public function __destruct() {
        $this->conn = null;
    }
    
    /**
     * Prevenir clonagem (Singleton)
     */
    private function __clone() {}
    
    /**
     * Prevenir unserialize (Singleton)
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}