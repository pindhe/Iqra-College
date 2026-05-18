-- ============================================
-- Add Avatar Column to Users Table
-- This script ensures the avatar column exists
-- ============================================

USE iqra;

-- Check if avatar column exists, if not add it
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'iqra' 
    AND TABLE_NAME = 'users' 
    AND COLUMN_NAME = 'avatar'
);

-- Add avatar column if it doesn't exist
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL AFTER phone',
    'SELECT "Avatar column already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create uploads/avatars directory note
-- Note: Make sure the directory exists: uploads/avatars/
-- The directory should have write permissions (chmod 755 or 777)

SELECT 'Avatar column setup complete!' AS status;
