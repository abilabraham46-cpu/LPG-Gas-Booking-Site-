-- MySQL Configuration Fix for Data Persistence
-- Run this after setting up your database

-- Set InnoDB settings for better data persistence
SET GLOBAL innodb_flush_log_at_trx_commit = 1;
SET GLOBAL sync_binlog = 1;
SET GLOBAL innodb_doublewrite = 1;

-- Ensure proper transaction handling
SET GLOBAL autocommit = 1;

-- Set durability settings
SET GLOBAL innodb_flush_method = 'O_DIRECT';

-- Display current settings
SELECT 'MySQL persistence settings updated!' AS status;
SHOW VARIABLES LIKE 'innodb_flush_log_at_trx_commit';
SHOW VARIABLES LIKE 'sync_binlog';
SHOW VARIABLES LIKE 'innodb_doublewrite';