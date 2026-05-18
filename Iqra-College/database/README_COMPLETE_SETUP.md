# Complete Database Setup - One File Solution

## Quick Setup

Run this **single file** to set up everything:

```sql
source database/complete_setup.sql
```

Or in phpMyAdmin:
1. Select `iqra` database
2. Go to SQL tab
3. Copy and paste the entire contents of `database/complete_setup.sql`
4. Click "Go"

## What This File Does

The `complete_setup.sql` file includes **everything** in one place:

1. ✅ **Adds Cashier Role** - Updates users table to include 'cashier' role
2. ✅ **Adds Student ID Column** - Adds student_id field to users table
3. ✅ **Creates Payments Table** - Complete payments table with all required columns
4. ✅ **Creates Payment Verifications Table** - Tracks verified payments
5. ✅ **Adds Payment Settings** - Adds payment_phone setting
6. ✅ **Creates Default Cashier** - Creates cashier account automatically

## Default Cashier Account

After running the setup, you can login with:

- **Email**: `cashier@iqracollege.com`
- **Password**: `cashier123`

⚠️ **Change the password after first login!**

## After Setup

### 1. Update Payment Phone Number

Run this SQL to set your payment phone number:

```sql
UPDATE settings 
SET setting_value = 'YOUR_PHONE_NUMBER' 
WHERE setting_key = 'payment_phone';
```

Replace `YOUR_PHONE_NUMBER` with your actual phone number (e.g., `+1234567890`)

### 2. Test the System

1. Register a new student → Should get auto-generated Student ID
2. Submit a payment → Should work without errors
3. Login as cashier → Verify the payment
4. Student should be enrolled automatically

## File Structure

- `complete_setup.sql` - **Use this one file for everything!**
- `add_cashier_system.sql` - Original migration (now included in complete_setup.sql)
- `add_payment_settings.sql` - Payment settings (now included in complete_setup.sql)
- `add_cashier.sql` - Cashier account (now included in complete_setup.sql)

## Troubleshooting

If you get foreign key errors:
- The script handles this automatically by disabling foreign key checks
- All tables are dropped and recreated in the correct order

If cashier login doesn't work:
- Make sure the cashier role was added successfully
- Check that the user was created in the users table

## Notes

- This will **delete existing payment data** if you run it again
- Student IDs are auto-generated on registration (not in this SQL file)
- All foreign key constraints are properly handled
