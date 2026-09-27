<?php
/**
 * Qndocs - 极简 PDO 封装（表名用 {name} 占位，自动加前缀）
 */
final class DB
{
    /** @var PDO|null */
    private static $pdo = null;
    private static $prefix = 'qn_';
    private static $error = '';

    public static function init(array $config): bool
    {
        self::$prefix = isset($config['prefix']) ? (string) $config['prefix'] : 'qn_';
        try {
            self::$pdo = self::make($config, true);
            self::$error = '';
            return true;
        } catch (Throwable $e) {
            self::$error = $e->getMessage();
            self::$pdo = null;
            return false;
        }
    }

    /** 建立连接。$withDatabase=false 用于安装时创建数据库。 */
    public static function make(array $config, bool $withDatabase = true): PDO
    {
        $host = $config['host'] ?? 'localhost';
        $port = (int) ($config['port'] ?? 3306);
        $charset = $config['charset'] ?? 'utf8mb4';
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=' . $charset;
        if ($withDatabase && !empty($config['name'])) {
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $config['name'] . ';charset=' . $charset;
        }
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES '{$charset}', sql_mode='NO_ENGINE_SUBSTITUTION'";
        }
        return new PDO($dsn, (string) ($config['user'] ?? ''), (string) ($config['pass'] ?? ''), $options);
    }

    public static function ready(): bool
    {
        return self::$pdo instanceof PDO;
    }

    public static function pdo(): ?PDO
    {
        return self::$pdo;
    }

    public static function error(): string
    {
        return self::$error;
    }

    public static function prefix(): string
    {
        return self::$prefix;
    }

    public static function table(string $name): string
    {
        return self::$prefix . $name;
    }

    private static function sql(string $sql): string
    {
        return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($m) {
            return self::$prefix . $m[1];
        }, $sql);
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        if (!self::$pdo instanceof PDO) {
            throw new RuntimeException('数据库未连接');
        }
        $stmt = self::$pdo->prepare(self::sql($sql));
        $stmt->execute($params);
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = [])
    {
        $row = self::q($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $params = [])
    {
        $v = self::q($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::q($sql, $params)->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $fields = [];
        foreach ($columns as $col) {
            $fields[] = '`' . str_replace('`', '', $col) . '`';
        }
        $sql = 'INSERT INTO {' . $table . '} (' . implode(', ', $fields) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';
        self::q($sql, array_values($data));
        return (int) self::$pdo->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = '`' . str_replace('`', '', $col) . '` = ?';
        }
        $sql = 'UPDATE {' . $table . '} SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        return self::exec($sql, array_merge(array_values($data), $params));
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::exec('DELETE FROM {' . $table . '} WHERE ' . $where, $params);
    }
}
