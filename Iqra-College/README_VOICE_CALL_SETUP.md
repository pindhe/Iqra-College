# Voice Call AI System Setup Guide

## Overview
This system enables AI-powered voice calls where callers can speak to an AI assistant that automatically responds to their questions about Iqra College.

## Features
- ✅ AI-powered voice responses using OpenAI
- ✅ Automatic conversation handling
- ✅ Call logging and statistics
- ✅ Twilio integration support
- ✅ Web-based testing interface
- ✅ Admin configuration panel

## Setup Instructions

### 1. Database Setup
Run the database setup script:
```sql
-- In phpMyAdmin or MySQL command line:
source database/add_voice_call_system.sql
```

Or manually run:
```bash
mysql -u root -p iqra < database/add_voice_call_system.sql
```

### 2. API Key Configuration

#### Option A: Via Admin Panel (Recommended)
1. Log in as admin
2. Navigate to: `/Iqra-College/admin/voice-call-config.php`
3. Enter your OpenAI API key
4. Configure phone number for transfers
5. Enable the system
6. Click "Save Configuration"

#### Option B: Via Database
```sql
UPDATE settings SET setting_value = 'your-openai-api-key-here' WHERE setting_key = 'openai_api_key';
```

### 3. Twilio Setup (For Real Phone Calls)

1. **Sign up for Twilio**: https://www.twilio.com/
2. **Get a phone number** from Twilio
3. **Configure webhook**:
   - In Twilio Console → Phone Numbers → Manage → Active Numbers
   - Set Voice & Fax → A CALL COMES IN → Webhook
   - URL: `https://yourdomain.com/Iqra-College/api/voice-call.php`
   - Method: POST

4. **Update transfer number** in admin panel with your Twilio number

### 4. Testing

#### Web-Based Test Interface
Visit: `/Iqra-College/api/test-voice.php`
- Type messages to test AI responses
- View conversation history
- Test different questions

#### Twilio Test
1. Call your Twilio phone number
2. The AI will greet you
3. Speak your question
4. AI will respond automatically
5. Continue conversation or say "zero" to end

## API Endpoints

### Voice Call Handler
**URL**: `/Iqra-College/api/voice-call.php`
**Method**: POST (Twilio webhook)
**Parameters**:
- `From`: Caller's phone number
- `CallSid`: Unique call identifier
- `SpeechResult`: Transcribed speech from caller
- `Digits`: DTMF tones pressed

### Test Interface
**URL**: `/Iqra-College/api/test-voice.php`
**Method**: GET/POST
**Purpose**: Web-based testing interface

## Configuration

### Admin Panel
Access: `/Iqra-College/admin/voice-call-config.php`

**Settings**:
- OpenAI API Key: Your OpenAI API key
- Phone Number: Number for transferring to human agents
- Enable/Disable: Toggle the voice call system

### Database Settings
All settings are stored in the `settings` table:
- `openai_api_key`: OpenAI API key
- `voice_call_number`: Transfer phone number
- `voice_call_enabled`: Enable/disable (1/0)

## How It Works

1. **Call Received**: System receives call via Twilio webhook
2. **Greeting**: AI greets the caller
3. **Speech Recognition**: Twilio transcribes caller's speech
4. **AI Processing**: OpenAI generates response
5. **Text-to-Speech**: Response is converted to speech
6. **Response**: AI speaks the response
7. **Continue**: System listens for next question or ends call

## Conversation Flow

```
Caller: "Hello"
AI: "Hello! Welcome to Iqra College. I'm your AI assistant. How can I help you today?"

Caller: "What courses do you offer?"
AI: [Provides course information] "Is there anything else I can help you with?"

Caller: "How do I enroll?"
AI: [Provides enrollment information] "Is there anything else I can help you with?"

Caller: "No, thank you"
AI: "Thank you for calling Iqra College. Have a great day!"
```

## Troubleshooting

### AI Not Responding
- Check OpenAI API key is configured correctly
- Verify API key has sufficient credits
- Check error logs in PHP error log

### Calls Not Working
- Verify Twilio webhook URL is correct
- Check Twilio account has active phone number
- Ensure database tables are created
- Check PHP error logs

### No Speech Recognition
- Verify Twilio account is active
- Check webhook is receiving POST requests
- Ensure SpeechResult parameter is being sent

## Security Notes

⚠️ **Important**: 
- Never commit API keys to version control
- Store API keys in database settings, not in code
- Use HTTPS for webhook URLs
- Regularly rotate API keys
- Monitor API usage to prevent abuse

## API Key Storage

The OpenAI API key is stored in:
- Database: `settings` table, `openai_api_key` key
- Config file: `config/api.php` (fallback only)

## Support

For issues or questions:
1. Check PHP error logs
2. Check Twilio call logs
3. Test using web interface first
4. Verify API key is valid and has credits

## Next Steps

1. ✅ Run database setup script
2. ✅ Configure OpenAI API key in admin panel
3. ✅ Set up Twilio account and webhook
4. ✅ Test using web interface
5. ✅ Test with actual phone call
6. ✅ Monitor call statistics in admin panel
