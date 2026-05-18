# English Learning Management System (LMS)

A comprehensive Learning Management System for English learning at college level, built with PHP, MySQL, and Tailwind CSS.

## 🚀 Features

### User Roles
- **Admin**: Manage teachers, courses, and view all students
- **Teacher**: Create courses, add lessons, create quizzes
- **Student**: Enroll in courses, view lessons, take quizzes, track progress

### Key Features
- ✅ Secure authentication system with password hashing
- ✅ Role-based access control
- ✅ Course management (Grammar, Writing, Reading, Listening)
- ✅ Lesson management with video support
- ✅ Interactive quizzes with multiple-choice questions
- ✅ Progress tracking for students
- ✅ Modern UI with 3D card effects and animations
- ✅ Responsive design (mobile & desktop)
- ✅ Blue & White theme

## 📋 Requirements

- XAMPP (PHP 7.4+ and MySQL)
- Web browser
- phpMyAdmin (included with XAMPP)

## 🛠️ Installation

### Step 1: Database Setup

1. Start XAMPP and ensure Apache and MySQL are running
2. Open phpMyAdmin (http://localhost/phpmyadmin)
3. Import the database schema:
   - Click on "Import" tab
   - Choose file: `database/schema.sql`
   - Click "Go" to import

### Step 2: Database Configuration

1. Open `config/database.php`
2. Update database credentials if needed (default XAMPP settings):
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', '');  // Empty for default XAMPP
   define('DB_NAME', 'iqra');
   ```

### Step 3: Create Upload Directories

Create the following directories (if they don't exist):
- `uploads/materials/` - For PDF and document files
- `uploads/videos/` - For video files (optional)

### Step 4: Access the Application

1. Place the project in: `C:\xampp\htdocs\Iqra-College\`
2. Open browser and navigate to: `http://localhost/Iqra-College/`

## 🔐 Default Login Credentials

### Admin Account
- **Email**: admin@lms.com
- **Password**: admin123

**Note**: Change the admin password after first login for security!

## 📁 Project Structure

```
Iqra-College/
├── admin/              # Admin panel pages
│   ├── index.php      # Admin dashboard
│   ├── teachers.php   # Manage teachers
│   ├── courses.php    # Manage courses
│   └── students.php   # View students
├── auth/              # Authentication pages
│   ├── login.php      # Login page
│   ├── register.php   # Student registration
│   └── logout.php     # Logout handler
├── config/            # Configuration files
│   └── database.php   # Database connection
├── includes/          # Shared PHP files
│   ├── auth.php       # Authentication functions
│   └── functions.php  # Helper functions
├── student/           # Student panel pages
│   ├── index.php      # Student dashboard
│   ├── courses.php    # View/enroll in courses
│   ├── course.php     # Course details
│   ├── lesson.php     # View lesson
│   ├── quiz.php       # Take quiz
│   └── results.php    # View results
├── teacher/           # Teacher panel pages
│   ├── index.php      # Teacher dashboard
│   ├── courses.php    # Manage courses
│   ├── lessons.php    # Manage lessons
│   └── quizzes.php    # Manage quizzes
├── database/          # Database files
│   └── schema.sql     # Database schema
├── uploads/           # Upload directories
│   ├── materials/     # PDF and documents
│   └── videos/        # Video files
├── index.php          # Home/landing page
└── README.md          # This file
```

## 🎨 Design Features

- **3D Card Effects**: Hover animations with 3D transforms
- **Modern UI**: Clean, professional design with Tailwind CSS
- **Color Scheme**: Blue & White theme throughout
- **Responsive**: Works on mobile, tablet, and desktop
- **Animations**: Smooth transitions and hover effects

## 🔒 Security Features

- ✅ Password hashing using PHP `password_hash()`
- ✅ Prepared statements (PDO) to prevent SQL injection
- ✅ Input sanitization and validation
- ✅ Session-based authentication
- ✅ Role-based access control
- ✅ CSRF protection ready (can be enhanced)

## 📚 Usage Guide

### For Admins:
1. Login with admin credentials
2. Add teachers from "Manage Teachers"
3. Create courses from "Manage Courses"
4. View all registered students

### For Teachers:
1. Login with teacher account (created by admin)
2. Create courses from "My Courses"
3. Add lessons (Grammar, Writing, Reading, Listening)
4. Create quizzes with multiple-choice questions

### For Students:
1. Register a new account or login
2. Browse and enroll in courses
3. View lessons and download materials
4. Take quizzes and view results
5. Track progress on dashboard

## 🗄️ Database Tables

- `users` - User accounts (admin, teacher, student)
- `courses` - Course information
- `enrollments` - Student-course relationships
- `lessons` - Lesson content
- `materials` - Learning materials (PDFs, etc.)
- `quizzes` - Quiz information
- `questions` - Quiz questions
- `results` - Student quiz results
- `lesson_progress` - Track lesson completion

## 🐛 Troubleshooting

### Database Connection Error
- Ensure MySQL is running in XAMPP
- Check database credentials in `config/database.php`
- Verify database `iqra` exists

### Page Not Found (404)
- Check that project is in correct directory: `C:\xampp\htdocs\Iqra-College\`
- Verify Apache is running in XAMPP
- Check file paths in navigation links

### Session Issues
- Ensure `session_start()` is called before any output
- Check PHP session configuration
- Clear browser cookies if needed

## 📝 Notes

- Default admin password should be changed after first login
- File uploads are configured but may need additional setup for large files
- Video URLs can be YouTube embeds or direct video links
- All user inputs are sanitized and validated

## 🔄 Future Enhancements

- File upload functionality for materials
- Email notifications
- Discussion forums
- Certificate generation
- Advanced analytics
- Mobile app integration

## 📄 License

This project is created for educational purposes.

---

**Developed with ❤️ for English Learning at College Level**
