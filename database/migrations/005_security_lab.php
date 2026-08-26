<?php
declare(strict_types=1);
return [
    'version' => '005_security_lab',
    'up' => [
        "CREATE TABLE IF NOT EXISTS security_lab_runs (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          scope_id BIGINT UNSIGNED NULL,
          tool VARCHAR(40) NOT NULL,
          profile VARCHAR(60) NOT NULL,
          command_text TEXT NOT NULL,
          status VARCHAR(20) NOT NULL DEFAULT 'running',
          exit_code INT NULL,
          output MEDIUMTEXT NULL,
          created_by BIGINT UNSIGNED NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          finished_at DATETIME NULL,
          duration_ms INT NULL,
          summary_json JSON NULL,
          INDEX(scope_id), INDEX(created_at)
        ) ENGINE=InnoDB",
    ],
];
