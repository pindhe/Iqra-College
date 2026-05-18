-- ============================================
-- Add WhatsApp API Settings
-- Run this to configure WhatsApp API for password reset
-- ============================================

USE iqra;

-- WhatsApp API Configuration Settings
INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES
('whatsapp_api_url', '', 'string', 'WhatsApp API endpoint URL (e.g., https://api.example.com/whatsapp/send)'),
('whatsapp_api_key', '', 'string', 'WhatsApp API key or token'),
('whatsapp_api_type', 'webhook', 'string', 'WhatsApp API type: webhook, twilio, chatapi, whatsapp_business'),
('whatsapp_api_token', '', 'string', 'Additional token for Twilio (auth token)'),
('whatsapp_from_number', '', 'string', 'WhatsApp sender number (for Twilio)')
ON DUPLICATE KEY UPDATE 
    setting_value = VALUES(setting_value),
    description = VALUES(description);

-- Instructions:
-- 1. Choose a WhatsApp API service (Twilio, ChatAPI, WhatsApp Business API, etc.)
-- 2. Update the settings above with your API credentials
-- 3. For Twilio:
--    - whatsapp_api_type = 'twilio'
--    - whatsapp_api_key = Your Account SID
--    - whatsapp_api_token = Your Auth Token
--    - whatsapp_from_number = Your Twilio WhatsApp number (e.g., +14155238886)
--
-- 4. For ChatAPI:
--    - whatsapp_api_type = 'chatapi'
--    - whatsapp_api_url = Your ChatAPI endpoint
--    - whatsapp_api_key = Your ChatAPI token
--
-- 5. For WhatsApp Business API:
--    - whatsapp_api_type = 'whatsapp_business'
--    - whatsapp_api_url = Your WhatsApp Business API endpoint
--    - whatsapp_api_key = Your access token
--
-- 6. For custom webhook:
--    - whatsapp_api_type = 'webhook'
--    - whatsapp_api_url = Your webhook URL
--    - whatsapp_api_key = Your API key (if required)

SELECT 'WhatsApp API settings added successfully!' as status;
SELECT 'Please update the settings with your WhatsApp API credentials.' as message;

