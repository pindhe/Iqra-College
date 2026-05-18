-- ============================================
-- UPDATE COURSES TABLE - Add Missing Fields
-- Run this if you already have a courses table
-- ============================================

USE iqra;

-- Add banner_image column if it doesn't exist
ALTER TABLE courses 
ADD COLUMN IF NOT EXISTS banner_image VARCHAR(255) NULL AFTER thumbnail;

-- Add preview_video column if it doesn't exist
ALTER TABLE courses 
ADD COLUMN IF NOT EXISTS preview_video VARCHAR(255) NULL AFTER banner_image;

-- Add discount_price column if it doesn't exist
ALTER TABLE courses 
ADD COLUMN IF NOT EXISTS discount_price DECIMAL(10,2) DEFAULT NULL AFTER price;

-- Add is_free column if it doesn't exist
ALTER TABLE courses 
ADD COLUMN IF NOT EXISTS is_free TINYINT(1) DEFAULT 0 AFTER discount_price;

-- Add access_days column if it doesn't exist
ALTER TABLE courses 
ADD COLUMN IF NOT EXISTS access_days INT DEFAULT 0 COMMENT '0 = lifetime access' AFTER duration;

-- Add max_students column if it doesn't exist
ALTER TABLE courses 
ADD COLUMN IF NOT EXISTS max_students INT DEFAULT NULL AFTER access_days;

-- Add enrolled_count column if it doesn't exist
ALTER TABLE courses 
ADD COLUMN IF NOT EXISTS enrolled_count INT DEFAULT 0 AFTER max_students;

-- Add has_certificate column if it doesn't exist
ALTER TABLE courses 
ADD COLUMN IF NOT EXISTS has_certificate TINYINT(1) DEFAULT 0 AFTER enrolled_count;

-- Add language column if it doesn't exist
ALTER TABLE courses 
ADD COLUMN IF NOT EXISTS language VARCHAR(50) DEFAULT 'English' AFTER has_certificate;

-- Add meta_title column if it doesn't exist
ALTER TABLE courses 
ADD COLUMN IF NOT EXISTS meta_title VARCHAR(255) NULL AFTER language;

-- Add meta_description column if it doesn't exist
ALTER TABLE courses 
ADD COLUMN IF NOT EXISTS meta_description VARCHAR(500) NULL AFTER meta_title;

-- Add slug column if it doesn't exist (with unique constraint)
ALTER TABLE courses 
ADD COLUMN IF NOT EXISTS slug VARCHAR(200) NULL AFTER title;

-- Add unique index on slug if it doesn't exist
CREATE UNIQUE INDEX IF NOT EXISTS idx_slug ON courses(slug);

-- Add index on level if it doesn't exist
CREATE INDEX IF NOT EXISTS idx_level ON courses(level);

-- Add index on price if it doesn't exist
CREATE INDEX IF NOT EXISTS idx_price ON courses(price);

-- Ensure level enum is correct
ALTER TABLE courses 
MODIFY COLUMN level ENUM('beginner', 'intermediate', 'advanced') DEFAULT 'beginner';

-- Ensure status enum is correct
ALTER TABLE courses 
MODIFY COLUMN status ENUM('draft', 'published', 'archived') DEFAULT 'draft';

-- ============================================
-- END OF UPDATE
-- ============================================
