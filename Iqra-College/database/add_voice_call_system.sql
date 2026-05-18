-- Voice Call AI System Database Setup
-- Run this script to set up the voice call system

USE iqra;

-- Create voice_calls table
CREATE TABLE IF NOT EXISTS voice_calls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caller_number VARCHAR(20),
    caller_name VARCHAR(255),
    call_sid VARCHAR(100) UNIQUE,
    status ENUM('incoming', 'answered', 'completed', 'failed') DEFAULT 'incoming',
    transcript TEXT,
    ai_response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_call_sid (call_sid),
    INDEX idx_created (created_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create voice_call_messages table
CREATE TABLE IF NOT EXISTS voice_call_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    call_sid VARCHAR(100),
    user_message TEXT,
    ai_response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_call_sid (call_sid),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add OpenAI API key to settings (if not exists)
INSERT INTO settings (setting_key, setting_value, created_at, updated_at)
VALUES ('openai_api_key', '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', NOW(), NOW())
ON DUPLICATE KEY UPDATE 
    setting_value = VALUES(setting_value),
    updated_at = NOW();

-- Add voice call phone number setting
INSERT INTO settings (setting_key, setting_value, created_at, updated_at)
VALUES ('voice_call_number', '+1234567890', NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Add voice call enabled setting
INSERT INTO settings (setting_key, setting_value, created_at, updated_at)
VALUES ('voice_call_enabled', '1', NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Create uploads/audio directory structure (note: this is handled by PHP, but documented here)
-- The PHP code will create: /Iqra-College/uploads/audio/

SELECT 'Voice Call AI System database setup completed successfully!' as status;
