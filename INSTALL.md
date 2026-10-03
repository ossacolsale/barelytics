# Install Barelytics with FTP/SFTP

The directly deployable runtime is [`public/barelytics/`](public/barelytics/). Upload its contents to a directory on your PHP site, such as `httpdocs/barelytics/`, then open `https://example.com/barelytics/install.php` (substitute your chosen path).

The browser installer checks PHP 8.1–8.5, PDO SQLite, JSON, sessions, data-directory permissions, SQLite read/write/delete, HTTPS, and webroot protection. If a requirement fails, use the hosting control panel or contact the provider; the page explains the failed check.

Create a random token of at least 32 characters in your password manager. Open `token-hash.html` locally or over HTTPS, hash the token, and upload the downloaded `setup-token.php` to the package's `data/` folder by FTP/SFTP. The file contains only a SHA-256 digest and returns 404 if requested directly. You can instead upload it as `setup.token` to the private data folder if your FTP account can access that folder. Then submit the original token and create an admin password in the browser. Setup locks permanently afterward.

Sign in at `/barelytics/admin.php` and copy its generated integration snippet. Use one method per page: JavaScript for static/HTML sites or the documented generic PHP function for server-rendered pages.

No SSH, shell, Composer, npm, build command, or cron is required. For the complete storage, Apache/Nginx, Plesk, backup, CSP, and retention instructions, see [docs/installation.md](docs/installation.md).

To recover a forgotten password without deleting statistics, create a new hash with `token-hash.html`, upload it as `reset.token` to the private data directory, and use the recovery form at `install.php`. The token file is consumed on success and can only replace the existing administrator password.
