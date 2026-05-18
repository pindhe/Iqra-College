# DeepSeek API Setup Guide

## Overview

The Messages feature uses DeepSeek AI to provide an intelligent chat assistant for students. Students can ask questions about courses, assignments, payments, and general learning support.

## Setup Instructions

### Step 1: Get DeepSeek API Key

1. Visit [DeepSeek Platform](https://platform.deepseek.com/)
2. Sign up or log in to your account
3. Navigate to API Keys section
4. Create a new API key
5. Copy your API key (keep it secure!)

### Step 2: Configure API Key

You have **two options** to set the API key:

#### Option A: Database Setting (Recommended)

Run this SQL in phpMyAdmin:

```sql
UPDATE settings 
SET setting_value = 'your-deepseek-api-key-here' 
WHERE setting_key = 'deepseek_api_key';
```

Replace `your-deepseek-api-key-here` with your actual API key.

#### Option B: Config File

Edit `config/api.php` and replace:

```php
define('DEEPSEEK_API_KEY', 'your-deepseek-api-key-here');
```

With your actual API key.

### Step 3: Verify Setup

1. Login as a student
2. Navigate to "Messages" in the sidebar
3. Try sending a message like "What courses am I enrolled in?"
4. You should receive an AI response

## Features

### AI Assistant Capabilities

The AI assistant can help students with:

- ✅ **Course Information** - Questions about enrolled courses
- ✅ **Assignments** - Help with assignment questions
- ✅ **Quizzes** - Quiz-related assistance
- ✅ **Payments** - Payment status and information
- ✅ **General Support** - Learning support and guidance

### Conversation History

- All conversations are saved in the database
- Students can see their chat history
- AI uses conversation context for better responses
- Last 10 messages are used as context

## Troubleshooting

### "AI service is not configured"

**Solution**: Make sure you've set the API key using one of the methods above.

### "AI service error"

**Possible causes**:
- Invalid API key
- API key expired
- Network issues
- API rate limit exceeded

**Solutions**:
1. Verify your API key is correct
2. Check your DeepSeek account status
3. Check your internet connection
4. Wait a few minutes if rate limited

### No response from AI

**Solutions**:
1. Check browser console for errors
2. Verify API key is set correctly
3. Check database connection
4. Ensure DeepSeek API is accessible

## API Costs

DeepSeek offers competitive pricing. Check their [pricing page](https://platform.deepseek.com/pricing) for current rates.

**Note**: Monitor your API usage to avoid unexpected charges.

## Security

- API keys are stored securely in the database
- Never commit API keys to version control
- Use environment variables in production
- Rotate API keys periodically

## Files Created

- `config/api.php` - API configuration
- `student/messages.php` - Messages page with AI chat
- `database/add_deepseek_setting.sql` - Database setting for API key

## Support

For issues:
1. Check DeepSeek API status
2. Verify API key is valid
3. Check database settings table
4. Review error messages in browser console
