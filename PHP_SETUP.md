## AmusePark (PHP + MySQL) Setup

### 1) Import the database
- Create a MySQL database named `amusepark`
- Import the SQL file: `server/amusepark.sql`

### 2) Configure PHP database connection
Recommended: copy `config.local.php.example` → `config.local.php`, then set:
- `DB_HOST`
- `DB_USER`
- `DB_PASS` (blank for default XAMPP root)
- `DB_NAME` (already `amusepark`)

### 3) Run with Apache + PHP (XAMPP/WAMP)
Put this project folder inside your web server root, for example:
- `C:\xampp\htdocs\amusement`

Then open:
- `http://localhost/amusement/login.php`

### 4) Admin login
The SQL seeds a default admin:
- **Email**: `admin@amusepark.com`
- **Password**: `Admin1234`

### 5) What’s already working in PHP
- `admin/rides.php`: add/edit/delete rides, including optional image upload (stored in DB as a data URL)
- `admin/ticket-types.php`: add/edit/delete ticket types, toggle active/inactive
- Changes are saved in the `amusepark` MySQL database

### 6) Old HTML pages
This project now uses PHP pages directly:
- Landing page: `index.php`
- Contact page: `contact.php`
- Customer pages: `rides.php`, `tickets.php`, `my-bookings.php`, `customer/dashboard.php`
- Admin pages: `admin/admin-dashboard.php`, `admin/rides.php`, `admin/ticket-types.php`, `admin/bookings.php`

