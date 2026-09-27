<?php
/**
 * Qndocs - 数据表结构定义与自检
 * 安装向导与「诊断修复」工具共用同一份定义，避免两边结构漂移。
 */

/** 全部数据表的结构定义 */
function qn_schema(): array
{
    return [
        'users' => [
            'columns' => [
                'id'         => 'int(10) unsigned NOT NULL AUTO_INCREMENT',
                'username'   => "varchar(64) NOT NULL DEFAULT ''",
                'password'   => "varchar(255) NOT NULL DEFAULT ''",
                'nickname'   => "varchar(64) NOT NULL DEFAULT ''",
                'email'      => "varchar(128) NOT NULL DEFAULT ''",
                'role'       => "varchar(20) NOT NULL DEFAULT 'editor'",
                'status'     => "tinyint(4) NOT NULL DEFAULT '1'",
                'last_login' => 'datetime DEFAULT NULL',
                'last_ip'    => "varchar(45) NOT NULL DEFAULT ''",
                'created_at' => 'datetime NOT NULL',
            ],
            'keys' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `username` (`username`)',
            ],
        ],

        'docs' => [
            'columns' => [
                'id'          => 'int(10) unsigned NOT NULL AUTO_INCREMENT',
                'parent_id'   => "int(10) unsigned NOT NULL DEFAULT '0'",
                'title'       => "varchar(200) NOT NULL DEFAULT ''",
                'slug'        => "varchar(191) NOT NULL DEFAULT ''",
                'type'        => "varchar(10) NOT NULL DEFAULT 'html'",
                'content'     => 'mediumtext',
                'description' => "varchar(500) NOT NULL DEFAULT ''",
                'keywords'    => "varchar(255) NOT NULL DEFAULT ''",
                'sort'        => "int(11) NOT NULL DEFAULT '0'",
                'status'      => "varchar(10) NOT NULL DEFAULT 'public'",
                'password'    => "varchar(100) NOT NULL DEFAULT ''",
                'is_home'     => "tinyint(4) NOT NULL DEFAULT '0'",
                'in_nav'      => "tinyint(4) NOT NULL DEFAULT '1'",
                'views'       => "int(10) unsigned NOT NULL DEFAULT '0'",
                'author_id'   => "int(10) unsigned NOT NULL DEFAULT '0'",
                'created_at'  => 'datetime NOT NULL',
                'updated_at'  => 'datetime NOT NULL',
            ],
            'keys' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `slug` (`slug`)',
                'KEY `parent_id` (`parent_id`)',
                'KEY `status` (`status`)',
                'KEY `sort` (`sort`)',
            ],
        ],

        'revisions' => [
            'columns' => [
                'id'         => 'int(10) unsigned NOT NULL AUTO_INCREMENT',
                'doc_id'     => "int(10) unsigned NOT NULL DEFAULT '0'",
                'title'      => "varchar(200) NOT NULL DEFAULT ''",
                'type'       => "varchar(10) NOT NULL DEFAULT 'html'",
                'content'    => 'mediumtext',
                'user_id'    => "int(10) unsigned NOT NULL DEFAULT '0'",
                'username'   => "varchar(64) NOT NULL DEFAULT ''",
                'created_at' => 'datetime NOT NULL',
            ],
            'keys' => [
                'PRIMARY KEY (`id`)',
                'KEY `doc_id` (`doc_id`)',
            ],
        ],

        'settings' => [
            'columns' => [
                'k' => "varchar(64) NOT NULL DEFAULT ''",
                'v' => 'mediumtext',
            ],
            'keys' => [
                'PRIMARY KEY (`k`)',
            ],
        ],

        'tags' => [
            'columns' => [
                'id'   => 'int(10) unsigned NOT NULL AUTO_INCREMENT',
                'name' => "varchar(40) NOT NULL DEFAULT ''",
                'slug' => "varchar(191) NOT NULL DEFAULT ''",
            ],
            'keys' => [
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `name` (`name`)',
            ],
        ],

        'doc_tags' => [
            'columns' => [
                'doc_id' => "int(10) unsigned NOT NULL DEFAULT '0'",
                'tag_id' => "int(10) unsigned NOT NULL DEFAULT '0'",
            ],
            'keys' => [
                'PRIMARY KEY (`doc_id`,`tag_id`)',
                'KEY `tag_id` (`tag_id`)',
            ],
        ],

        'files' => [
            'columns' => [
                'id'         => 'int(10) unsigned NOT NULL AUTO_INCREMENT',
                'name'       => "varchar(255) NOT NULL DEFAULT ''",
                'path'       => "varchar(255) NOT NULL DEFAULT ''",
                'url'        => "varchar(500) NOT NULL DEFAULT ''",
                'size'       => "int(10) unsigned NOT NULL DEFAULT '0'",
                'mime'       => "varchar(100) NOT NULL DEFAULT ''",
                'user_id'    => "int(10) unsigned NOT NULL DEFAULT '0'",
                'created_at' => 'datetime NOT NULL',
            ],
            'keys' => [
                'PRIMARY KEY (`id`)',
                'KEY `user_id` (`user_id`)',
            ],
        ],
    ];
}

/** 生成建表 SQL */
function qn_schema_create_sql(string $table, array $definition, string $prefix): string
{
    $lines = [];
    foreach ($definition['columns'] as $name => $column) {
        $lines[] = '  `' . $name . '` ' . $column;
    }
    foreach ($definition['keys'] as $key) {
        $lines[] = '  ' . $key;
    }
    return 'CREATE TABLE IF NOT EXISTS `' . $prefix . $table . "` (\n" . implode(",\n", $lines)
        . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

/** 全部表名（带前缀） */
function qn_schema_tables(string $prefix): array
{
    $out = [];
    foreach (array_keys(qn_schema()) as $table) {
        $out[$table] = $prefix . $table;
    }
    return $out;
}

/**
 * 检查数据表状态
 * @return array table => ['table','exists','missing','count','auto_missing']
 */
function qn_schema_status(string $prefix): array
{
    $out = [];
    foreach (qn_schema() as $table => $definition) {
        $full   = $prefix . $table;
        $exists = true;
        $have   = [];
        try {
            $rows = DB::all('SHOW COLUMNS FROM `' . $full . '`');
            foreach ($rows as $row) {
                $have[] = strtolower((string) $row['Field']);
            }
            if (!$rows) {
                $exists = false;
            }
        } catch (Throwable $e) {
            $exists = false;
        }

        $missing     = [];
        $autoMissing = [];
        if ($exists) {
            foreach ($definition['columns'] as $name => $column) {
                if (!in_array(strtolower($name), $have, true)) {
                    $missing[] = $name;
                    if (stripos($column, 'AUTO_INCREMENT') !== false) {
                        $autoMissing[] = $name;
                    }
                }
            }
        }

        $count = 0;
        if ($exists) {
            try {
                $count = (int) DB::val('SELECT COUNT(*) FROM `' . $full . '`');
            } catch (Throwable $e) {
                $count = -1;
            }
        }

        $out[$table] = [
            'table'        => $full,
            'exists'       => $exists,
            'missing'      => $missing,
            'auto_missing' => $autoMissing,
            'count'        => $count,
        ];
    }
    return $out;
}

/** 补建缺失的数据表与字段，返回操作日志 */
function qn_schema_repair(string $prefix): array
{
    $log   = [];
    $stat  = qn_schema_status($prefix);
    $tables = qn_schema();

    foreach ($stat as $table => $info) {
        $full = $info['table'];
        if (!$info['exists']) {
            try {
                DB::exec(qn_schema_create_sql($table, $tables[$table], $prefix));
                $log[] = '已创建数据表 ' . $full;
            } catch (Throwable $e) {
                $log[] = '创建数据表 ' . $full . ' 失败：' . $e->getMessage();
            }
            continue;
        }
        if (!$info['missing']) {
            continue;
        }
        foreach ($info['missing'] as $column) {
            if (in_array($column, $info['auto_missing'], true)) {
                $log[] = '字段 ' . $full . '.' . $column . ' 缺失且为自增主键，无法自动补加，建议备份数据后删除该表再重新修复。';
                continue;
            }
            try {
                DB::exec('ALTER TABLE `' . $full . '` ADD COLUMN `' . $column . '` '
                    . $tables[$table]['columns'][$column]);
                $log[] = '已为 ' . $full . ' 补加字段 ' . $column;
            } catch (Throwable $e) {
                $log[] = '为 ' . $full . ' 补加字段 ' . $column . ' 失败：' . $e->getMessage();
            }
        }
    }

    if (!$log) {
        $log[] = '数据库结构完整，无需修复。';
    }
    return $log;
}
