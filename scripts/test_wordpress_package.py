import shutil
import subprocess
import tempfile
import unittest
import zipfile
from pathlib import Path


@unittest.skipUnless(shutil.which("php") and shutil.which("zip"), "Package test requires PHP and zip; CI also runs it in the WordPress job.")
class WordPressPackageTests(unittest.TestCase):
    def test_local_storage_and_stale_archive_entries_never_enter_the_package(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "scripts").mkdir()
            builder = Path(__file__).with_name("build-wordpress-plugin.php")
            shutil.copy2(builder, root / "scripts" / builder.name)
            core = root / "public/barelytics"
            # Minimal source tree, with deliberately unrelated private/local files.
            source_files = [
                ".htaccess", "account.php", "admin.php", "admin.css", "audit.php",
                "cleanup.php", "diagnostics.php", "install.php", "token-hash.html",
                "token-hash.js", "track.php", "track.js", "src/.htaccess",
                "src/Barelytics.php", "src/AdminApi.php", "src/AdminPresentation.php", "src/Automation.php", "src/DailyReach.php", "data/.htaccess",
                "admin-ui/index.html", "admin-ui/app.js", "admin-ui/admin.css",
                "admin-ui/config.js", "admin-ui/brand-mark.png", "admin-ui/README.md",
            ]
            for relative in source_files:
                path = core / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(b"source fixture")
            for relative in ("data/analytics.sqlite", "data/setup-token.php", "var/reset.token", ".env", "backup.zip", "src/operator-secret.php"):
                path = core / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(b"synthetic-private-fixture")
            plugin = root / "integrations/wordpress/barelytics"
            plugin.mkdir(parents=True)
            for name in ("barelytics.php", "storage.php"):
                (plugin / name).write_bytes(b"plugin fixture")
            (plugin / "operator-secret.php").write_bytes(b"synthetic-private-fixture")
            (plugin.parent / "README.md").write_text("Plugin fixture")
            output = root / "build/barelytics-wordpress.zip"
            output.parent.mkdir()
            with zipfile.ZipFile(output, "w") as archive:
                archive.writestr("barelytics/stale-private.token", b"synthetic-private-fixture")
            subprocess.run(["php", str(root / "scripts" / builder.name)], check=True, capture_output=True)
            with zipfile.ZipFile(output) as archive:
                names = archive.namelist()
                self.assertIn("barelytics/barelytics-core/src/Automation.php", names)
                self.assertIn("barelytics/storage.php", names)
                self.assertFalse(any(b"synthetic-private-fixture" in archive.read(name) for name in names if not name.endswith("/")))
            # A linked manifest entry cannot smuggle an external local file.
            (core / "track.js").unlink()
            (core / "track.js").symlink_to(core / "var/reset.token")
            rejected = subprocess.run(["php", str(root / "scripts" / builder.name)], capture_output=True)
            self.assertNotEqual(rejected.returncode, 0)
