# Database Setup - Complete Guide

## One File Setup

All database setup is now in **ONE file**: `database.sql`

## Quick Start

### Option 1: phpMyAdmin (Recommended)

1. Open phpMyAdmin
2. Click on "SQL" tab
3. Copy the entire contents of `database/database.sql`
4. Paste and click "Go"

### Option 2: Command Line

```bash
mysql -u root -p iqra < database/database.sql
```

## What's Included

The `database.sql` file includes **everything**:

### Core Tables
- ✅ Users (with cashier role and student_id)
- ✅ Categories
- ✅ Courses (with all columns)
- ✅ Sections/Chapters
- ✅ Enrollments
- ✅ Lessons (with sections support)
- ✅ Materials
- ✅ Quizzes (with description)
- ✅ Questions
- ✅ Quiz Attempts (with all columns)
- ✅ Student Answers
- ✅ Lesson Progress

### Payment System
- ✅ Payments table
- ✅ Payment Verifications table
- ✅ Payment phone number setting

### Assignments
- ✅ Assignments table
- ✅ Assignment Submissions table

### Certificates
- ✅ Certificates table (with certificate_number)

### Communication
- ✅ Messages table
- ✅ Notifications table
- ✅ Announcements table

### System Tables
- ✅ Settings table
- ✅ Activity Logs table
- ✅ Password Resets table
- ✅ Attendance table
- ✅ Reviews table
- ✅ Course Requirements table
- ✅ Course Outcomes table

### Default Data
- ✅ Default admin accounts
- ✅ Default cashier account
- ✅ Default categories
- ✅ Default settings (including payment_phone)

## Default Accounts

After running `database.sql`, you can login with:

### Admin Accounts
- **Email**: `admin@lms.com` | **Password**: `password`
- **Email**: `pindhe@gmail.com` | **Password**: `123`

### Cashier Account
- **Email**: `cashier@iqracollege.com` | **Password**: `cashier123`

⚠️ **IMPORTANT**: Change all passwords after first login!

## After Setup

### 1. Update Payment Phone Number

```sql
UPDATE settings 
SET setting_value = 'YOUR_PHONE_NUMBER' 
WHERE setting_key = 'payment_phone';
```

Replace `YOUR_PHONE_NUMBER` with your actual phone number (e.g., `+1234567890`)

### 2. Test the System

1. Register a new student → Should get auto-generated Student ID
2. Submit a payment → Should work without errors
3. Login as cashier → Verify payments
4. Student enrollment → Automatic after payment verification

## Features Included

- ✅ **Cashier Role System** - Complete payment verification
- ✅ **Student ID Auto-Generation** - Format: STU-YYYY-XXXXX
- ✅ **Payment System** - Full payment workflow
- ✅ **Course Sections** - Organize lessons by weeks/chapters
- ✅ **Quiz System** - Complete quiz functionality
- ✅ **Assignments** - Assignment submission and grading
- ✅ **Certificates** - Certificate generation support
- ✅ **Notifications** - User notification system
- ✅ **All Required Columns** - Everything from all migrations

## File Structure

- `database.sql` - **THE ONLY FILE YOU NEED** - Complete database setup
- `create_cashier.php` - Optional: Create cashier account via PHP
- `fix_database.php` - Optional: Diagnostic/fix tool
- `check_payments_table.php` - Optional: Check payments table structure

## Notes

- This file is **idempotent** - Can be run multiple times safely
- Uses `CREATE TABLE IF NOT EXISTS` and `ON DUPLICATE KEY UPDATE`
- All foreign key constraints are properly handled
- All indexes are included for optimal performance

## Troubleshooting

If you get errors:
1. Make sure you're using the `iqra` database
2. Check that MySQL version supports all features
3. Run `fix_database.php` if you have structure issues

## Support

For issues:
1. Check the error message
2. Verify database connection
3. Ensure all required tables exist
4. Check foreign key constraints
