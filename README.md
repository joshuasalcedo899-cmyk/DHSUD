# DHSUD Mail Tracker

[![License: ISC](https://img.shields.io/badge/License-ISC-blue.svg)](https://opensource.org/licenses/ISC)
[![Version](https://img.shields.io/badge/Version-1.0.1-green.svg)](https://github.com/joshuasalcedo899-cmyk/DHSUD/releases)
[![Node.js](https://img.shields.io/badge/Node.js-14+-blue.svg)](https://nodejs.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4+-purple.svg)](https://www.php.net/)
[![Windows](https://img.shields.io/badge/Platform-Windows-0078D4.svg)](https://www.microsoft.com/windows)

> A comprehensive mail tracking and management system for DHSUD (Department of Human Settlements and Urban Development), built with PHP backend, MySQL database, and optional Electron desktop application.

## Table of Contents

- [About](#about)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
- [Project Structure](#project-structure)
- [Configuration](#configuration)
- [Deployment](#deployment)
- [Authentication](#authentication)
- [Database](#database)
- [Scripts](#scripts)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [Support](#support)
- [License](#license)

## About

DHSUD Mail Tracker is a full-stack application designed to track, manage, and archive mail across various departments and services. It provides both web-based and desktop interfaces for tracking mail through different departments.

## Features

### 🎯 Core Features

- **Mail Tracking** - Real-time tracking of mail across all departments
- **User Authentication** - Secure login with bcrypt password hashing
- **Multi-Department Support** - Track mail through AFD, EMES, HOA, JRS, ORD, and more
- **PDF Receipt Generation** - Automatic receipt generation and download
- **Mail Archive** - Archive and recover historical mail records
- **Performance Metrics** - Analytics and tracking statistics
- **RESTful API** - Programmatic access to all features
- **Database Management** - Centralized MySQL data storage

### 🛠️ Technical Features

- **Multi-Platform** - Web application and Windows desktop client
- **Responsive Design** - Mobile-friendly CSS interface
- **PDF Generation** - Server-side rendering with dompdf and Playwright
- **Browser Automation** - Advanced functionality via Playwright/Puppeteer
- **Desktop Integration** - Standalone Electron application
- **Automated Deployment** - Windows installer generation with NSIS

## Requirements

### Minimum Requirements

| Component | Version | Purpose |
|-----------|---------|---------|
| Windows | 10/11 | Operating System |
| XAMPP | Latest | Apache, PHP, MySQL |
| PHP | 7.4+ | Web Application Runtime |
| MySQL | 5.7+ | Database |
| Node.js | 14+ | Build Tools & Dependencies |
| PowerShell | 5.0+ | Automation Scripts |

**Administrator Access:** Required for service setup and installation

### Optional Components

- **Electron** 37.2.1+ - For desktop application development
- **NSIS** - For creating Windows installers

## Installation

### Prerequisites

1. Install [XAMPP](https://www.apachefriends.org/) with Apache, PHP, and MySQL
2. Install [Node.js](https://nodejs.org/) 14 or higher
3. Clone or download the repository

```bash
git clone https://github.com/joshuasalcedo899-cmyk/DHSUD.git
cd DHSUD
```

### Quick Start (Web Application)

1. **Start XAMPP services** via the XAMPP Control Panel:
   - Click "Start" for Apache
   - Click "Start" for MySQL
   - Verify Apache is running at `http://localhost/`

2. **Install dependencies:**

   ```powershell
   cd C:\xampp\htdocs\DHSUD
   npm run install:deps
   ```

   If network issues occur with Playwright download:
   ```powershell
   npm run install:deps:skip-browser
   ```

3. **Setup the database:**
   - Open `http://localhost/phpmyadmin`
   - Create new database named `dshudmail_db`
   - Import the schema: `database/dshudmail_db.sql`

4. **Configure connection settings:**
   - Update `config.php` with your MySQL credentials if different from defaults
   - Default: `host=localhost`, `user=root`, `password=""`, `database=dshudmail_db`

5. **Access the application:**
   - Admin Dashboard: `http://localhost/DHSUD/pages/Admin_LogIn.php`
   - Public Tracking: `http://localhost/DHSUD/pages/Tracking_Page.php`

   **Default Credentials (Development Only):**
   - Username: `admin`
   - Password: `dhsudr4a2019`

### Desktop Application Installation

For a standalone desktop application with embedded services:

```powershell
npm run installer:build
```

**Build Options:**

| Command | Description |
|---------|-------------|
| `npm run installer:build` | Standard NSIS installer |
| `npm run installer:build:skip-browser` | Skip Playwright browser download |
| `npm run installer:build:portable` | Portable executable (no installation) |

## Usage

### Web Application

After installation, access the application through your browser:

- **Admin Interface:** `http://localhost/DHSUD/pages/Admin_LogIn.php`
  - Add and manage mail records
  - View tracking information
  - Generate receipts
  - Archive operations

- **Public Tracking:** `http://localhost/DHSUD/pages/Tracking_Page.php`
  - Track mail without authentication
  - View mail status updates
  - Download tracking information

- **Department-Specific Pages:**
  - AFD, EMES, HOA, JRS, ORD, PHILPOST, PHSD, PRLS, ELUPD

### Desktop Application

Launch the desktop app to run the web interface in a standalone window with integrated services:

```powershell
npm run desktop:dev
```

## Project Structure

```
DHSUD/
├── pages/                    # Frontend pages
│   ├── Admin_LogIn.php      # Admin login page
│   ├── Home_Page.php        # Dashboard
│   ├── Tracking_Page.php    # Public tracking interface
│   ├── AFD_Page.php         # AFD department
│   ├── EMES_Page.php        # EMES department
│   ├── JRS_Tracking_Page.php # JRS tracking
│   └── [other department pages]
├── api/                      # RESTful API endpoints
│   ├── Add.php              # Add new mail record
│   ├── Edit Mail.php        # Edit existing record
│   ├── Delete.php           # Delete record
│   ├── get-tracking.php     # Get tracking information
│   ├── download-receipt.php # Generate receipt
│   ├── save-tracked-db.php  # Save to database
│   ├── jrs-track.php        # JRS tracking API
│   └── [other API endpoints]
├── database/                 # Database files
│   ├── dshudmail_db.sql     # Main schema
│   └── performance_indexes.sql # Indexes
├── electron/                 # Desktop app
│   ├── main.js              # Electron main process
│   ├── preload.js           # Preload scripts
│   └── [electron config]
├── puppeteer/               # Browser automation
├── dompdf/                  # PDF generation library
├── JRS_PDFs/                # Generated PDF storage
├── assets/                  # Images and static files
├── build/                   # Build configuration (NSIS installer)
├── scripts/                 # Automation scripts
│   ├── install-deps.ps1     # Dependency installer
│   ├── build-installer.ps1  # Installer builder
│   ├── set-exe-icon.ps1     # Icon configuration
│   └── setup-new-device.ps1 # New device setup
├── auth.php                 # Authentication functions
├── config.php               # Database configuration
├── main.css                 # Stylesheet
├── index.php                # Landing page
└── package.json             # Node dependencies
```

## Authentication

### System Overview

The application uses PHP session-based authentication with bcrypt password hashing for security.

### Key Functions

| Function | Purpose |
|----------|---------|
| `loginUser()` | Authenticate user credentials |
| `registerUser()` | Create new user account |
| `isLoggedIn()` | Check current authentication status |
| `logoutUser()` | Destroy session and logout |
| `requireLogin()` | Enforce login requirement on page |
| `getCurrentUser()` | Get current user information |

### Default Credentials

> ⚠️ **Important:** Change these credentials in production!

- **Username:** `admin`
- **Password:** `dhsudr4a2019`

See [AUTHENTICATION_SETUP.md](./AUTHENTICATION_SETUP.md) for detailed setup instructions.

## Database

### Schema Overview

| Component | Details |
|-----------|---------|
| Database | `dshudmail_db` |
| Tables | Users, Mail Records, Archives, Tracking Metrics |
| Indexes | Performance optimization indexes available |

### Setup Instructions

1. Open phpMyAdmin: `http://localhost/phpmyadmin`
2. Create new database: `dshudmail_db`
3. Import schema: `database/dshudmail_db.sql`
4. Optional: Import indexes: `database/performance_indexes.sql`

### Configuration

Update [config.php](./config.php) with your database credentials:

```php
$host = "localhost";           // MySQL host
$username = "root";            // MySQL username
$password = "";                // MySQL password
$database = "dshudmail_db";   // Database name
```

## Scripts

### Development Scripts

```powershell
npm run install:deps                    # Install dependencies
npm run desktop:dev                     # Launch Electron dev mode
npm run set:exe-icon                    # Configure executable icon
```

### Build & Distribution

```powershell
npm run installer:build                 # Create NSIS installer
npm run installer:build:skip-browser    # Build without Playwright
npm run installer:build:portable        # Create portable .exe
npm run desktop:dist                    # Build distributable installer
npm run desktop:portable                # Build portable version
```

## Deployment

### Local Development

- Apache: `http://localhost`
- MySQL: Local service
- Single machine access
- Ideal for development and testing

### LAN + HTTPS

- Configure Windows hosts file for domain resolution
- Setup SSL/TLS certificate
- Multi-device network access
- Shared XAMPP instance

See [service-setup.txt](./service-setup.txt) for detailed configuration.

### Desktop Application

- Standalone Windows executable
- Embedded Apache, PHP, and MySQL
- NSIS installer format
- Portable version available

See [INSTALLER_BUILD.txt](./INSTALLER_BUILD.txt) for build details.

## Configuration

### Database Configuration

Database connection details are stored in [config.php](./config.php):

```php
$host = "localhost";           // MySQL host
$username = "root";            // MySQL username
$password = "";                // MySQL password
$database = "dshudmail_db";   // Database name
```

### Environment Variables (Desktop App)

For desktop application deployment:

- `DHSUD_START_URL` - Custom start URL (default: `http://127.0.0.1/DHSUD`)
- `DHSUD_MYSQL_ROOT_PASSWORD` - MySQL root password

### Application Settings

Key configuration locations:
- API endpoints: `api/` directory
- Page templates: `pages/` directory
- Styles: [main.css](./main.css)
- Database schemas: `database/` directory

## Troubleshooting

### Service Issues

| Problem | Solution |
|---------|----------|
| Apache/MySQL won't start | Check XAMPP installation, verify services aren't already running |
| "Port 80 in use" error | Stop other services, check Task Manager for conflicting processes |
| XAMPP Control Panel error | Run as Administrator, repair XAMPP installation |

### Database Issues

| Problem | Solution |
|---------|----------|
| Connection refused | Verify MySQL is running in XAMPP, check credentials in `config.php` |
| Database not found | Create `dshudmail_db` in phpMyAdmin and import schema |
| Table errors | Re-import `database/dshudmail_db.sql` |

### Application Issues

| Problem | Solution |
|---------|----------|
| Login not working | Verify `users` table exists and has admin account |
| PDF generation fails | Install Playwright: `npm run install:deps`, check disk space |
| Desktop app won't start | Ensure XAMPP at `C:\xampp`, check environment variables |

### File Permissions

- Ensure `cache/` directory is writable
- `JRS_PDFs/` requires write permissions
- Check Windows permissions for user account

### Performance Issues

- Import performance indexes: `database/performance_indexes.sql`
- Check `cache/` directory size
- Monitor XAMPP resource usage
- Verify sufficient disk space for PDF generation

### Additional Debugging

- Check XAMPP error logs: `C:\xampp\apache\logs\error.log`
- PHP error log: `C:\xampp\php\logs\php_error.log`
- MySQL error log: `C:\xampp\mysql\data\mysql_error.log`

## Documentation

Additional documentation files:

- [AUTHENTICATION_SETUP.md](./AUTHENTICATION_SETUP.md) - Authentication system configuration
- [INSTALLATION_SETUP.md](./INSTALLATION_SETUP.md) - Detailed installation guide
- [INSTALLER_BUILD.txt](./INSTALLER_BUILD.txt) - Desktop installer build documentation
- [service-setup.txt](./service-setup.txt) - Windows service configuration
- [INSTALL_DEPENDENCIES.txt](./INSTALL_DEPENDENCIES.txt) - Dependency information

## Contributing

Contributions are welcome! Please follow these guidelines:

1. **Before Starting**
   - Ensure all dependencies are installed: `npm run install:deps`
   - Keep your fork up to date with main branch
   - Create a new branch for your feature: `git checkout -b feature/your-feature-name`

2. **Code Standards**
   - Follow existing code structure and naming conventions
   - Use meaningful commit messages
   - Test your changes locally before committing
   - Ensure no console errors or warnings

3. **Testing**
   - Test all affected features locally
   - Verify database operations work correctly
   - Check PDF generation if modifications affect it
   - Test both web and desktop interfaces if applicable

4. **Documentation**
   - Update README.md if adding features
   - Document new API endpoints
   - Add comments for complex logic
   - Update relevant markdown files

5. **Submitting Changes**
   - Push to your fork
   - Create a Pull Request with clear description
   - Reference any related issues
   - Wait for review and address feedback

### Code Style

- **PHP:** Follow PSR-12 coding standards
- **JavaScript:** Use consistent indentation
- **CSS:** Group related properties
- **Comments:** Keep documentation current

## Support

### Getting Help

- **Issues:** Use the [GitHub Issues](https://github.com/joshuasalcedo899-cmyk/DHSUD/issues) tracker
- **Questions:** Create a discussion or issue with `[QUESTION]` prefix
- **Bugs:** Report with reproduction steps and system info

### Resources

- [GitHub Repository](https://github.com/joshuasalcedo899-cmyk/DHSUD)
- [Issues & Discussions](https://github.com/joshuasalcedo899-cmyk/DHSUD/issues)
- [XAMPP Documentation](https://www.apachefriends.org/)
- [PHP Documentation](https://www.php.net/docs.php)
- [MySQL Documentation](https://dev.mysql.com/doc/)
- [Electron Documentation](https://www.electronjs.org/docs)

## Security Notes

⚠️ **Important Security Reminders:**

- Change default admin credentials in production
- Never commit `.env` or credentials to version control
- Use HTTPS for production deployments
- Regularly backup the `dshudmail_db` database
- Keep XAMPP and dependencies updated
- Review and restrict file permissions appropriately
- Monitor error logs for suspicious activity
- Implement rate limiting for API endpoints in production

## License

This project is licensed under the **ISC License** - see [package.json](./package.json) for details.

### License Terms

Free to use, modify, and distribute. Include original copyright notice and license when redistributing.

For more details, visit [opensource.org/licenses/ISC](https://opensource.org/licenses/ISC).

---

## Footer

**Version:** 1.0.1  
**Last Updated:** June 5, 2026  
**Maintainer:** [@joshuasalcedo899-cmyk](https://github.com/joshuasalcedo899-cmyk)

Built with ❤️ for DHSUD
