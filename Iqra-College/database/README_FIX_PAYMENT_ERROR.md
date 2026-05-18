# Fix Payment Submission Error

## Quick Solution

You're getting: **"Failed to submit payment. Database structure mismatch."**

### Option 1: Automatic Fix (Easiest) ⭐

1. Open this URL in your browser:
   ```
   http://localhost/Iqra-College/database/fix_database.php
   ```

2. The script will automatically:
   - Check your database structure
   - Create/fix the payments table
   - Create payment_verifications table
   - Add student_id column
   - Add cashier role

3. Click the link at the end to go back to courses

### Option 2: Manual SQL Fix

1. Open phpMyAdmin
2. Select the `iqra` database
3. Go to the SQL tab
4. Copy and paste the entire contents of `database/QUICK_FIX.sql`
5. Click "Go" to execute

### Option 3: Full Migration

Run the complete migration:
```sql
source database/add_cashier_system.sql
```

## What's Wrong?

The error means your `payments` table either:
- Doesn't exist
- Has the wrong structure (missing columns)
- Was created with an old schema

## After Fixing

1. Try submitting a payment again
2. The error should be gone
3. Payment will be saved as "pending"
4. Cashier can verify it in the dashboard

## Still Having Issues?

1. Check the error message - it now includes a clickable link to fix automatically
2. Run `database/check_payments_table.php` to diagnose the issue
3. Make sure you're using the `iqra` database
