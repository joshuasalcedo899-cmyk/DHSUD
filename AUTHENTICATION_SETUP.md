# Authentication System Setup Guide

## Overview
A complete authentication system has been implemented for your DHSUD application with login, logout, and session management.

## Files Created/Modified

### New Files:
1. **auth.php** - Core authentication functions
   - `loginUser($username, $password)` - Authenticates user
   - `registerUser($username, $password, $email)` - Creates new user
   - `isLoggedIn()` - Checks if user is authenticated
   - `logoutUser()` - Destroys session and logs out user
   - `requireLogin()` - Redirects to login if not authenticated
   - `getCurrentUser()` - Returns current user data

2. **pages/logout.php** - Logout handler
   - Destroys session and redirects to login page

3. **database/create_users_table.sql** - Database schema
   - Creates `users` table with proper structure
   - Includes sample admin account

### Modified Files:
1. **pages/Admin_LogIn.php** - Updated with:
   - POST request handling
   - Password validation against database
   - Session creation on successful login
   - Error message display
   - Redirect to Home_Page on success
   - Redirect to Home_Page if already logged in

2. **pages/Home_Page.php** - Updated with:
   - Authentication requirement (redirects to login if not authenticated)
   - Welcome message with username
   - Logout button in top-right corner

## Database Setup

### Step 1: Create the Users Table
Run the SQL script to create the users table:

1. Open phpMyAdmin
2. Select your `dhsudmail_db` database
3. Go to SQL tab
4. Copy and paste the contents of `database/create_users_table.sql`
5. Execute the query

OR run via MySQL command line:
```bash
mysql -u root -p dhsudmail_db < database/create_users_table.sql
```

### Step 2: Default Admin Account
After running the SQL script, you'll have a default admin account:
- **Username:** admin
- **Password:** admin123

## How to Use

### Login Process:
1. Navigate to `http://localhost/DHSUD/pages/Admin_LogIn.php`
2. Enter username: `admin`
3. Enter password: `admin123`
4. Click "Log In"
5. You'll be redirected to the Home Page

### Logout Process:
1. Click the "Logout" button in the top-right corner of Home Page
2. You'll be redirected to the login page

### Protected Pages:
- **Home_Page.php** - Requires authentication
- **Tracking_Page.php** - Can be made protected similarly if needed

## Adding New Users

To add new users to the system, you can:

### Method 1: Direct Database Insert
```sql
INSERT INTO `users` (`username`, `email`, `password`) VALUES 
('newuser', 'user@example.com', PASSWORD('userpassword'));
```

### Method 2: Create a Registration Page
Use the `registerUser($username, $password, $email)` function from auth.php

## Security Features Implemented

1. **Password Hashing** - Uses PHP's `password_hash()` with BCRYPT algorithm
2. **Session Management** - Secure session handling with $_SESSION
3. **Input Validation** - Checks for empty/invalid inputs
4. **SQL Injection Prevention** - Uses prepared statements with PDO
5. **Error Handling** - Generic error messages to prevent information leakage
6. **Session Redirect** - Redirects logged-in users away from login page
7. **Access Control** - `requireLogin()` function protects pages

## Important Notes

1. **Change Default Password** - Immediately change the default admin password after first login
2. **Session Security** - Sessions are stored in server memory and automatically cleared on logout
3. **Cookie Settings** - PHP session uses secure cookies (configured in php.ini)
4. **User Feedback** - Error messages are displayed on login failure without revealing specific reasons

## Troubleshooting

### "Invalid username or password" on correct credentials
- Ensure the users table exists in database
- Verify the admin user was inserted
- Check database connection in config.php

### Login page redirects loop
- Clear browser cookies
- Check $_SESSION settings in php.ini
- Ensure session.save_path is writable

### Can't access Home_Page without login
- This is intended behavior - authentication is working
- Go to login page first at Admin_LogIn.php

## Next Steps

1. ✅ Run the database SQL script to create users table
2. ✅ Test login with admin/admin123
3. ✅ Change admin password
4. ✅ Add additional users as needed
5. Optional: Apply `requireLogin()` to other pages like Tracking_Page.php
