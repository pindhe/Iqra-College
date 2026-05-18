# Cashier Role-Based Payment System

## Overview
This system implements a role-based payment verification system with three roles: Student, Teacher, and Cashier.

## Database Setup

Run the migration file to set up the required tables:
```sql
source database/add_cashier_system.sql
```

Or execute it in phpMyAdmin.

## Features

### 1. Auto Student ID Generation
- When a student registers, a unique Student ID is automatically generated
- Format: `STU-YYYY-XXXXX` (e.g., STU-2024-00001)
- The ID is displayed to the student after registration

### 2. Payment Verification System
- Students submit payment information through the course page
- Cashiers verify payments through the cashier dashboard
- Upon verification:
  - Payment status is updated to "verified"
  - Student is automatically enrolled in the course
  - Course access is activated

### 3. Course Access Control
- Students cannot access courses without verified payment
- Payment status is checked before course access
- Clear error messages guide students to pay

### 4. Teacher Access
- Teachers can only see students with verified payments
- Student lists are filtered to show only paid students

## User Roles

### Student
- Registers and receives auto-generated Student ID
- Submits payment information for courses
- Can only access courses after payment verification
- Views payment status on course pages

### Cashier
- Views pending payment submissions
- Verifies or rejects payments
- Automatically enrolls students upon verification
- Tracks daily revenue and verification statistics

### Teacher
- Views only students with verified payments
- Manages course content for enrolled (paid) students

## Files Modified/Created

1. **Database Migration**: `database/add_cashier_system.sql`
   - Adds cashier role to users table
   - Adds student_id field
   - Creates payments table
   - Creates payment_verifications table

2. **Registration**: `auth/register.php`
   - Auto-generates Student ID on registration

3. **Helper Functions**: `includes/functions.php`
   - `generateStudentId()` - Generates unique student IDs
   - `hasPaidForCourse()` - Checks payment status
   - `getStudentPaymentStatus()` - Gets all payment statuses

4. **Cashier Dashboard**: `cashier/index.php`
   - Payment verification interface
   - Statistics and reports

5. **Course Enrollment**: `student/courses.php`
   - Payment submission form
   - Payment status checking
   - Access control

6. **Course Access**: `student/course.php`
   - Payment verification before access

7. **Login**: `auth/login.php`
   - Cashier role redirect

## Usage Flow

1. **Student Registration**
   - Student registers → Receives Student ID automatically
   - Student ID format: STU-2024-00001

2. **Payment Submission**
   - Student selects a course
   - Clicks "Pay & Enroll"
   - Submits payment details (amount, method, reference)
   - Payment status: "pending"

3. **Cashier Verification**
   - Cashier views pending payments
   - Verifies payment → System automatically:
     - Updates payment status to "verified"
     - Creates payment verification record
     - Enrolls student in course
     - Activates course access

4. **Course Access**
   - Student can now access the course
   - Teacher can see the student in their course

## Security Features

- Payment verification required before course access
- Role-based access control
- Automatic enrollment after verification
- Payment history tracking
- Student ID uniqueness enforcement

## Testing

1. Register a new student → Check for auto-generated Student ID
2. Submit a payment → Check pending payments in cashier dashboard
3. Verify payment → Check automatic enrollment
4. Try accessing course without payment → Should be blocked
5. Access course after verification → Should work
