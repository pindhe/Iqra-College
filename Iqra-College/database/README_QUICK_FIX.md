# Quick Fix for Payment Submission Error

## Problem
If you see the error: **"Failed to submit payment. Database structure mismatch."**

This means the `payments` table is missing the `phone_number` column.

## Solution

### Option 1: Automatic Fix (Recommended)
1. Open your browser
2. Navigate to: `http://localhost/Iqra-College/database/fix_database.php`
3. The script will automatically:
   - Check if the `phone_number` column exists
   - Add it if missing
   - Fix any other database structure issues

### Option 2: Manual Fix via phpMyAdmin
1. Open phpMyAdmin
2. Select your database (usually `iqra`)
3. Click on the "SQL" tab
4. Copy and paste the contents of `QUICK_FIX.sql`
5. Click "Go" to execute

### Option 3: Command Line
```bash
mysql -u root -p iqra < database/QUICK_FIX.sql
```

## What the Fix Does
- Adds `phone_number VARCHAR(20)` column to the `payments` table
- Adds an index on `phone_number` for better performance
- Does NOT delete any existing data
- Safe to run multiple times (checks if column exists first)

## After Running the Fix
1. Try submitting a payment again
2. The payment form should now work correctly
3. Phone numbers will be stored in the database

## Verification
To verify the fix worked:
1. Go to phpMyAdmin
2. Select the `iqra` database
3. Click on the `payments` table
4. Check the "Structure" tab
5. You should see a `phone_number` column

## Need Help?
If you still encounter issues:
1. Check PHP error logs
2. Verify database connection in `config/database.php`
3. Make sure you have proper database permissions
