-- Quick Fix for Payments Table
-- Adds phone_number column if it doesn't exist
-- Run this in phpMyAdmin or MySQL command line

USE iqra;

-- Add phone_number column to payments table if it doesn't exist
SET @dbname = DATABASE();
SET @tablename = 'payments';
SET @columnname = 'phone_number';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT 1', -- Column exists, do nothing
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(20) NULL AFTER payment_reference')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add index for phone_number if column was added
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  CONCAT('ALTER TABLE ', @tablename, ' ADD INDEX idx_phone_number (phone_number)'),
  'SELECT 1'
));
PREPARE addIndexIfExists FROM @preparedStatement;
EXECUTE addIndexIfExists;
DEALLOCATE PREPARE addIndexIfExists;

SELECT 'Quick fix completed! phone_number column added to payments table.' as status;
