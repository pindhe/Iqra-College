# Role-Based Access Control (RBAC) System

## Overview
This RBAC system implements comprehensive access control for three main roles: Admin, Teacher, and Student, with level-based restrictions and assignment management.

## Database Setup

### 1. Run the Migration
Execute the SQL migration file to set up all required tables:
```sql
source database/rbac_system.sql
```
Or run it in phpMyAdmin.

### 2. Tables Created

#### `teacher_course_assignments`
- Stores which teachers are assigned to which courses and levels
- Admin assigns teachers to specific course-level combinations
- Teachers can only see/manage courses they're assigned to

#### `student_level_assignments`
- Stores which level each student is assigned to for each course
- Admin assigns students to levels after enrollment
- Students can only access content for their assigned level

#### Updated Tables
- `enrollments`: Added `assigned_level` and `enrollment_status` columns
- `payments`: Added approval fields for cash payments
- `lessons`: Added `level` column for level-specific lessons
- `users`: Status field for enable/disable accounts

## Role Permissions

### 1️⃣ ADMIN (Super Admin – Full Access)

**Full System Control:**
- ✅ Create, update, delete, and manage:
  - Courses
  - Course levels (Beginner, Intermediate, Advanced)
  - Teachers
  - Students
- ✅ Assign teachers to specific:
  - Courses
  - Course levels (e.g., Beginner only)
- ✅ Assign students to course levels after enrollment
- ✅ View all teachers and all students
- ✅ Control:
  - Payments
  - Enrollment approvals
  - Cash payment confirmation
- ✅ Enable or disable user accounts
- ✅ Monitor:
  - Student progress
  - Teacher activities
  - Course performance

**Admin Pages:**
- `/admin/assign-teachers.php` - Assign teachers to courses & levels
- `/admin/assign-students.php` - Assign students to levels
- `/admin/manage-users.php` - Enable/disable user accounts
- `/admin/courses.php` - Manage all courses
- `/admin/teachers.php` - Manage all teachers
- `/admin/students.php` - View all students

### 2️⃣ TEACHER (Limited to Assigned Courses & Levels)

**Restricted Access:**
- ✅ Can only see courses assigned by Admin
- ✅ Can only see students enrolled in assigned levels
- ✅ Can create, update, and delete:
  - Lessons (for assigned courses)
  - Modules
  - Quizzes
  - Assignments
- ✅ Can manage:
  - Quiz creation & grading
  - Assignment creation & grading
  - Lesson content (videos, PDFs, text)
  - Track student progress within assigned levels only

**Restrictions:**
- ❌ No access to payments
- ❌ No access to other teachers' courses
- ❌ No access to course levels not assigned
- ❌ Cannot see students from other levels

**Example:**
- If assigned to "Beginner" level of "English Course"
- Teacher can only see Beginner students
- Teacher cannot see Intermediate or Advanced students
- Teacher can only manage Beginner content

### 3️⃣ STUDENT (Restricted by Payment & Level)

**Access Control:**
- ✅ View all available courses (public catalog)
- ✅ Select a course
- ✅ Must Pay & Enroll before accessing any content
- ✅ After successful payment:
  - Enrollment is confirmed
  - Admin assigns the student to a level (e.g., Beginner)
  - Student can access only lessons of their assigned level

**Level Restriction Rule:**
- A Beginner student:
  - ❌ Cannot access Intermediate or Advanced content
  - ✅ Can only study Beginner lessons
- Progression to next level:
  - Requires Admin approval
  - Or course completion rules

**Payment Flow:**
1. Student selects course
2. Student pays (online or cash)
3. Admin approves payment (for cash)
4. Admin assigns student to level
5. Student can access level-specific content

## Workflow

### Teacher Assignment Flow
1. Admin creates/imports courses
2. Admin goes to "Assign Teachers" page
3. Admin selects:
   - Teacher
   - Course
   - Level (Beginner/Intermediate/Advanced)
4. Teacher can now see and manage that course-level combination
5. Teacher can only see students enrolled in that level

### Student Enrollment Flow
1. Student browses course catalog
2. Student selects a course
3. Student pays (online or cash)
4. If cash payment: Admin approves payment
5. Student is enrolled (status: pending)
6. Admin assigns student to a level (e.g., Beginner)
7. Enrollment status: approved
8. Student can now access Beginner-level content only

### Level-Based Content Access
1. Lessons can be assigned to specific levels
2. If lesson has no level (NULL), it's accessible to all
3. Student can only access lessons at or below their assigned level
4. Level hierarchy: Beginner < Intermediate < Advanced

## Helper Functions

### For Teachers
- `isTeacherAssignedToCourseLevel($teacherId, $courseId, $level)` - Check if teacher is assigned
- `getTeacherAssignments($teacherId)` - Get all assignments for a teacher
- `getTeacherAccessibleCourses($teacherId)` - Get courses teacher can access
- `getStudentsByCourseLevel($courseId, $level)` - Get students in specific level

### For Students
- `getStudentAssignedLevel($studentId, $courseId)` - Get student's assigned level
- `canStudentAccessLesson($studentId, $courseId, $lessonLevel)` - Check lesson access

### For Admins
- `assignTeacherToCourseLevel($teacherId, $courseId, $level, $assignedBy)` - Assign teacher
- `assignStudentToLevel($studentId, $courseId, $level, $assignedBy, $notes)` - Assign student
- `getStudentsPendingLevelAssignment()` - Get students waiting for assignment

## User Account Management

### Enable/Disable Accounts
- Admin can enable or disable any user account
- Disabled users cannot log in
- Status is checked on every login attempt
- Disabled users are automatically logged out

## Security Features

1. **Role-Based Access Control**: Users can only access resources based on their role
2. **Level Restrictions**: Students can only access content for their assigned level
3. **Teacher Assignment**: Teachers can only see assigned courses and levels
4. **Account Status**: Inactive accounts are blocked from login
5. **Payment Verification**: Cash payments require admin approval
6. **Enrollment Approval**: Students need admin approval for level assignment

## Migration Notes

### Backward Compatibility
- If `teacher_course_assignments` table doesn't exist, teachers see all their courses (backward compatible)
- If `student_level_assignments` table doesn't exist, students can access all content (backward compatible)
- System gracefully handles missing tables/columns

### Testing
1. Run migration: `database/rbac_system.sql`
2. Create test users (admin, teacher, student)
3. Create a course
4. Assign teacher to course and level
5. Enroll student and assign level
6. Verify access restrictions work correctly

## Files Modified/Created

### Database
- `database/rbac_system.sql` - Migration file

### Authentication
- `includes/auth.php` - Added user status checking
- `includes/functions.php` - Added RBAC helper functions

### Admin Pages
- `admin/assign-teachers.php` - Teacher assignment interface
- `admin/assign-students.php` - Student level assignment interface
- `admin/manage-users.php` - User account management
- `admin/index.php` - Added RBAC links

### Teacher Pages
- `teacher/courses.php` - Updated to show only assigned courses

### Student Pages
- (To be updated) - Will restrict content by assigned level

## Next Steps

1. ✅ Database migration created
2. ✅ Admin pages created
3. ✅ Authentication updated
4. ✅ Helper functions added
5. ✅ Teacher pages updated
6. ⏳ Student pages need level restrictions
7. ⏳ Lesson access control needs implementation
8. ⏳ Payment approval workflow needs integration

## Support

For issues or questions:
1. Check database tables exist: `SHOW TABLES;`
2. Verify user status: `SELECT id, name, status FROM users;`
3. Check assignments: `SELECT * FROM teacher_course_assignments;`
4. Check student levels: `SELECT * FROM student_level_assignments;`
