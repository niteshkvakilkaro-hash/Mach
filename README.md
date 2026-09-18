# MACH Home Services — Website + CRM

Home appliance repair website with lead CRM, bookings, technician app, payments, complaints and feedback.
PHP 8.1+, MySQL / MariaDB, Bootstrap 5, vanilla JS.

## Run locally (XAMPP)

1. Put the project in `C:\xampp\htdocs\mach` and start Apache + MySQL.
2. Create the database: import `database/schema.sql` in phpMyAdmin (it creates `mach_crm`).
3. Copy the config files and fill them in:
   - `config/app.example.php` → `config/app.php` (set `app_key`: `php -r "echo base64_encode(random_bytes(32));"`)
   - `config/database.example.php` → `config/database.php`
4. Optional demo data: `php database/seed-demo.php`
5. Open http://localhost/mach — admin at http://localhost/mach/admin
   (default login `admin@mach.local` / `Admin@12345` — **change it right away**).

## Docs

- `docs/DEPLOYMENT.md` — going live on Hostinger (step by step)
- `docs/ARCHITECTURE.md` — structure and modules
- `docs/SECURITY.md` — security measures

Build the upload package with `php tools/build-deploy.php`.
