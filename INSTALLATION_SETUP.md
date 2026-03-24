# DHSUD Mail Tracker � Installation and Setup Guide

This document is ready to paste into your project documentation. It covers local setup on Windows with XAMPP, database initialization, Node/Playwright dependencies for PDF generation, optional HTTPS/LAN setup, and optional desktop installer build.

## Scope
- Local web app setup (Apache + PHP via XAMPP)
- MySQL database initialization
- Node.js dependencies for PDF generation
- Optional LAN + HTTPS for multiple devices
- Optional desktop installer build (Electron/NSIS)

## System Requirements
- Windows PC
- XAMPP installed at `C:\xampp`
- Project folder at `C:\xampp\htdocs\DHSUD`
- PowerShell
- Administrator access (hosts file + Apache service changes)

## Quick Start (Local Only)
1. Start Apache and MySQL in XAMPP Control Panel.
2. Confirm Apache is running at `http://localhost/`.
3. Open PowerShell in the project folder:
   ```powershell
   cd C:\xampp\htdocs\DHSUD
   ```
4. Install Node dependencies (recommended):
   ```powershell
   npm run install:deps
   ```
   If your network blocks browser download:
   ```powershell
   npm run install:deps:skip-browser
   ```
5. Create the database and import the schema:
   1. Open phpMyAdmin: `http://localhost/phpmyadmin`
   2. Create a database named `dshudmail_db`
   3. Import `database/dshudmail_db.sql`
6. Confirm database credentials in `config.php` match your local MySQL setup.
7. Open the login page:
   `http://localhost/DHSUD/pages/Admin_LogIn.php`

## Database Notes
- The default database name is `dshudmail_db`.
- If you want the authentication system, also follow `AUTHENTICATION_SETUP.md`.

## PDF Generation (Playwright)
PDF generation requires Node.js on the same machine that runs Apache/PHP.

Checklist:
- `JRS_PDFs` exists and is writable
- Playwright dependencies are installed via `npm run install:deps`

Quick test (replace the tracking number):
`http://localhost/DHSUD/api/download-receipt.php?tracking=YOUR_TRACKING_NUMBER`

## Optional: HTTPS + LAN Access (New Device Setup)
Use this if the app must be accessed by other devices on the same network using a local domain (for example `dhsud.local`).

1. Open PowerShell as Administrator.
2. Run the setup script:
   ```powershell
   cd C:\xampp\htdocs\DHSUD
   .\scripts\setup-new-device.ps1
   ```
3. Open after setup:
   `https://dhsud.local/pages/Admin_LogIn.php`

Common options:
- Set LAN IP explicitly:
  ```powershell
  .\scripts\setup-new-device.ps1 -LanIp 192.168.1.7
  ```
- Skip dependencies:
  ```powershell
  .\scripts\setup-new-device.ps1 -SkipDependencies
  ```
- Skip certificate setup:
  ```powershell
  .\scripts\setup-new-device.ps1 -SkipCertificateSetup
  ```
- Dry run (no changes):
  ```powershell
  .\scripts\setup-new-device.ps1 -DryRun
  ```
- Custom domain:
  ```powershell
  .\scripts\setup-new-device.ps1 -Domain your-domain.local
  ```

Client trust setup (other PCs):
1. Copy the root CA from the server:
   `C:\Users\joshu\AppData\Local\mkcert\rootCA.pem`
2. On each client (PowerShell as Administrator), run:
   ```powershell
   cd C:\xampp\htdocs\DHSUD
   .\scripts\install-client-ca.ps1 -RootCAPath C:\path\to\rootCA.pem -ServerIp 192.168.1.7 -Domain dhsud.local
   ```

## Optional: Desktop Installer Build (Electron/NSIS)
Use this to create a Windows installer.

1. Ensure `installer\xampp` has either a portable XAMPP zip or extracted `apache`, `mysql`, and `php` folders.
2. Build the installer from the project root:
   ```powershell
   npm run installer:build
   ```
   To skip the Playwright browser download:
   ```powershell
   npm run installer:build:skip-browser
   ```
   For a portable package:
   ```powershell
   npm run installer:build:portable
   ```
3. Output goes to `desktop-dist`.

## Troubleshooting
- DB connection errors: confirm credentials in `config.php` and ensure MySQL is running in XAMPP.
- PDF download fails: run `npm run install:deps`, verify Playwright is installed, and confirm `JRS_PDFs` is writable.
- HTTPS not trusted: install mkcert, rerun `scripts\setup-new-device.ps1`, then install the root CA on client devices using `scripts\install-client-ca.ps1`.

## Related Documents
- `INSTALL_DEPENDENCIES.txt`
- `AUTHENTICATION_SETUP.md`
- `service-setup.txt`
- `INSTALLER_BUILD.txt`
