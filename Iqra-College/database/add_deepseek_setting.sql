-- Add DeepSeek API Key Setting
-- Run this SQL to add the setting for DeepSeek API key

USE iqra;

-- Insert DeepSeek API key setting
INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES
('deepseek_api_key', '', 'string', 'DeepSeek API key for AI chat assistant')
ON DUPLICATE KEY UPDATE 
    setting_value = VALUES(setting_value),
    description = VALUES(description);

-- Instructions:
-- 1. Get your API key from: https://platform.deepseek.com/
-- 2. Update the setting value:
--    UPDATE settings SET setting_value = 'your-api-key-here' WHERE setting_key = 'deepseek_api_key';
-- 3. Or set it in config/api.php as DEEPSEEK_API_KEY constant
