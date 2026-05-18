# WhatsApp Password Reset Setup Guide

## Overview

The forgot password feature sends verification codes via WhatsApp to users' registered phone numbers. This guide explains how to configure the WhatsApp API integration.

## Features

- ✅ Password reset via WhatsApp verification code
- ✅ 6-digit verification code sent to user's phone
- ✅ 15-minute expiration for security
- ✅ Support for multiple WhatsApp API providers

## Setup Instructions

### Step 1: Choose a WhatsApp API Service

You can use any of these services:

1. **Twilio WhatsApp API** (Recommended for production)
   - Sign up: https://www.twilio.com/
   - Get WhatsApp-enabled number
   - Use Account SID and Auth Token

2. **ChatAPI**
   - Sign up: https://www.chatapi.com/
   - Get API endpoint and token

3. **WhatsApp Business API**
   - Official WhatsApp Business API
   - Requires business verification

4. **Custom Webhook**
   - Your own WhatsApp API endpoint
   - Custom authentication

### Step 2: Configure Database Settings

Run the SQL to add settings:

```sql
-- In phpMyAdmin or MySQL command line:
source database/add_whatsapp_settings.sql
```

Or manually update settings:

```sql
UPDATE settings SET setting_value = 'your-api-url' WHERE setting_key = 'whatsapp_api_url';
UPDATE settings SET setting_value = 'your-api-key' WHERE setting_key = 'whatsapp_api_key';
UPDATE settings SET setting_value = 'webhook' WHERE setting_key = 'whatsapp_api_type';
```

### Step 3: Configure Based on Your Service

#### Option A: Twilio WhatsApp API

```sql
UPDATE settings SET setting_value = 'twilio' WHERE setting_key = 'whatsapp_api_type';
UPDATE settings SET setting_value = 'your-account-sid' WHERE setting_key = 'whatsapp_api_key';
UPDATE settings SET setting_value = 'your-auth-token' WHERE setting_key = 'whatsapp_api_token';
UPDATE settings SET setting_value = '+14155238886' WHERE setting_key = 'whatsapp_from_number';
```

#### Option B: ChatAPI

```sql
UPDATE settings SET setting_value = 'chatapi' WHERE setting_key = 'whatsapp_api_type';
UPDATE settings SET setting_value = 'https://eu.chatapi.com/instance12345/message?token=abc123' WHERE setting_key = 'whatsapp_api_url';
UPDATE settings SET setting_value = 'your-chatapi-token' WHERE setting_key = 'whatsapp_api_key';
```

#### Option C: WhatsApp Business API

```sql
UPDATE settings SET setting_value = 'whatsapp_business' WHERE setting_key = 'whatsapp_api_type';
UPDATE settings SET setting_value = 'https://graph.facebook.com/v18.0/PHONE_NUMBER_ID/messages' WHERE setting_key = 'whatsapp_api_url';
UPDATE settings SET setting_value = 'your-access-token' WHERE setting_key = 'whatsapp_api_key';
```

#### Option D: Custom Webhook

```sql
UPDATE settings SET setting_value = 'webhook' WHERE setting_key = 'whatsapp_api_type';
UPDATE settings SET setting_value = 'https://your-api.com/whatsapp/send' WHERE setting_key = 'whatsapp_api_url';
UPDATE settings SET setting_value = 'your-api-key' WHERE setting_key = 'whatsapp_api_key';
```

### Step 4: Test the Integration

1. Go to: `/Iqra-College/auth/forgot-password.php`
2. Enter your email or phone number
3. Check your WhatsApp for the verification code
4. Enter the code to reset your password

## How It Works

1. **User requests password reset**
   - User enters email or phone on forgot-password.php
   - System finds user account

2. **Verification code generation**
   - System generates 6-digit code
   - Creates reset token in database
   - Sets 15-minute expiration

3. **WhatsApp message sent**
   - Code sent to user's registered phone via WhatsApp
   - Message includes code and expiration time

4. **Code verification**
   - User enters code on reset-password.php
   - System verifies code matches

5. **Password reset**
   - User sets new password
   - Old password reset tokens invalidated
   - User can login with new password

## API Endpoint

The system uses: `/Iqra-College/api/whatsapp-send.php`

**Request Format:**
```json
{
  "phone": "+621234567890",
  "message": "Your verification code is: 123456"
}
```

**Response Format:**
```json
{
  "success": true,
  "message": "WhatsApp message sent successfully"
}
```

## Phone Number Format

- Phone numbers should include country code
- Format: `+[country code][number]`
- Example: `+621234567890` (Indonesia)
- System automatically formats local numbers

## Security Features

- ✅ 15-minute code expiration
- ✅ One-time use tokens
- ✅ Secure password hashing
- ✅ Session-based verification
- ✅ Token invalidation after use

## Troubleshooting

### Messages Not Sending

1. **Check API credentials**
   - Verify API URL is correct
   - Check API key/token is valid
   - Ensure API type matches your service

2. **Check phone number format**
   - Must include country code
   - Format: `+[country][number]`
   - Remove spaces and special characters

3. **Check API service status**
   - Verify your WhatsApp API account is active
   - Check API quotas/limits
   - Review API logs for errors

### Code Not Received

1. **Check user's phone number**
   - Verify phone is registered in database
   - Ensure phone number is correct format

2. **Check WhatsApp API logs**
   - Review error logs in PHP error log
   - Check API service dashboard

3. **Test API directly**
   - Use API endpoint directly to test
   - Verify API credentials work

### Development/Testing Mode

For development, the system will log messages instead of sending if no API is configured:

```
WhatsApp Message to +621234567890: Your verification code is: 123456
```

To enable real sending, configure your WhatsApp API settings.

## Notes

- Users must have a phone number registered in their account
- Admin can add phone numbers via user management
- WhatsApp API costs may apply (check your provider)
- Consider rate limiting for production use

