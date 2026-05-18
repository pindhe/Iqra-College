# Create Cashier Account

## Default Cashier Credentials

After running `add_cashier.sql`, you can login with:

- **Email**: `cashier@iqracollege.com`
- **Password**: `cashier123`

## How to Create a Cashier Account

### Option 1: Using SQL File (Recommended)

1. Open phpMyAdmin
2. Select the `iqra` database
3. Go to SQL tab
4. Run the SQL file: `database/add_cashier.sql`

Or execute:
```sql
INSERT INTO users (name, email, password, role) VALUES
('Cashier', 'cashier@iqracollege.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier');
```

### Option 2: Create Custom Cashier Account

To create a cashier with custom credentials, use this PHP code to generate the password hash:

```php
<?php
$password = 'your_password_here';
$hash = password_hash($password, PASSWORD_DEFAULT);
echo $hash;
?>
```

Then insert into database:
```sql
INSERT INTO users (name, email, password, role) VALUES
('Cashier Name', 'cashier@example.com', 'GENERATED_HASH_HERE', 'cashier');
```

### Option 3: Using Admin Panel (if available)

If you have admin access, you can create a cashier account through the admin panel by:
1. Going to Users/Teachers management
2. Adding a new user with role "cashier"

## Change Password

To change the cashier password, generate a new hash and update:

```sql
UPDATE users 
SET password = 'NEW_PASSWORD_HASH' 
WHERE email = 'cashier@iqracollege.com' AND role = 'cashier';
```

## Security Note

⚠️ **Important**: Change the default password immediately after first login for security purposes.
