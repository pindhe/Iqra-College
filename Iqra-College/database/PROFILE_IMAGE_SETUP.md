# Profile Image Setup Guide

## Database Structure

The `users` table already includes an `avatar` column:

```sql
avatar VARCHAR(255) NULL
```

This column stores the filename of the uploaded profile image.

## Database Migration

If you need to add the avatar column to an existing database, run:

```sql
ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL AFTER phone;
```

Or use the provided migration script:
- `database/add_avatar_column.sql`

## File Storage

Profile images are stored in:
- **Directory**: `uploads/avatars/`
- **Allowed formats**: JPG, JPEG, PNG, GIF, WEBP
- **Maximum size**: 5MB per image
- **Naming**: Unique filenames generated using `uniqid()_timestamp.extension`

## Features Implemented

### Student Profile
- ✅ Profile image upload section
- ✅ Image preview with fallback to initial avatar
- ✅ Automatic deletion of old images when uploading new ones
- ✅ Display in sidebar and header

### Teacher Profile
- ✅ Profile image upload section
- ✅ Image preview with fallback to initial avatar
- ✅ Automatic deletion of old images when uploading new ones
- ✅ Display in sidebar and header

## Usage

1. **Upload Profile Image**:
   - Navigate to Profile page (Student or Teacher)
   - Click "Upload New Image" button
   - Select an image file (JPG, PNG, GIF, or WEBP)
   - Click "Upload Image"
   - The image will be saved and displayed throughout the portal

2. **View Profile Image**:
   - Profile images appear in:
     - Sidebar (top user info section)
     - Header (user dropdown button)
     - Profile page (large preview)

3. **Remove Profile Image**:
   - Upload a new image to replace the current one
   - Old images are automatically deleted

## Directory Permissions

Ensure the `uploads/avatars/` directory has write permissions:

```bash
chmod 755 uploads/avatars/
# or
chmod 777 uploads/avatars/
```

## Database Queries

### Get user with avatar:
```sql
SELECT id, name, email, avatar FROM users WHERE id = ?;
```

### Update user avatar:
```sql
UPDATE users SET avatar = ? WHERE id = ?;
```

### Get all users with avatars:
```sql
SELECT id, name, email, avatar FROM users WHERE avatar IS NOT NULL;
```

## Security Notes

- File uploads are validated for type and size
- Only image files are accepted
- Filenames are sanitized and unique
- Old files are automatically cleaned up
- Images are stored outside the web root (relative to project)
