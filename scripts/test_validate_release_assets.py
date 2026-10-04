from __future__ import annotations

import gzip
import io
import sys
import tarfile
import tempfile
import unittest
import zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from validate_release_assets import validate_archive  # noqa: E402


UI = {
    "admin-ui/index.html": b"<html></html>",
    "admin-ui/app.js": b"console.log('ok')",
    "admin-ui/admin.css": b"body {}",
    "admin-ui/config.js": b"export default {}",
}


def make_zip(path: Path, files: dict[str, bytes]) -> None:
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as archive:
        for name, content in files.items():
            archive.writestr(name, content)


def make_tar(files: dict[str, bytes], mode: str = "w:gz") -> bytes:
    output = io.BytesIO()
    with tarfile.open(fileobj=output, mode=mode) as archive:
        for name, content in files.items():
            info = tarfile.TarInfo(name)
            info.size = len(content)
            archive.addfile(info, io.BytesIO(content))
    return output.getvalue()


class ReleaseAssetValidationTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.directory = Path(self.temp.name)

    def tearDown(self) -> None:
        self.temp.cleanup()

    def test_valid_zip(self) -> None:
        path = self.directory / "barelytics-php-v1.0.0.zip"
        make_zip(path, UI)
        validate_archive(path)

    def test_valid_tar_gz(self) -> None:
        path = self.directory / "barelytics-1.0.0.tar.gz"
        path.write_bytes(make_tar(UI))
        validate_archive(path)

    def test_valid_gem_with_nested_data_tar_gz(self) -> None:
        path = self.directory / "barelytics-1.0.0.gem"
        outer = io.BytesIO()
        with tarfile.open(fileobj=outer, mode="w:") as archive:
            data = make_tar(UI)
            info = tarfile.TarInfo("data.tar.gz")
            info.size = len(data)
            archive.addfile(info, io.BytesIO(data))
            metadata = gzip.compress(b"--- !ruby/object:Gem::Specification\n")
            info = tarfile.TarInfo("metadata.gz")
            info.size = len(metadata)
            archive.addfile(info, io.BytesIO(metadata))
        path.write_bytes(outer.getvalue())
        validate_archive(path)

    def test_truncated_archive_reports_format(self) -> None:
        path = self.directory / "barelytics-node-v1.0.0.tgz"
        path.write_bytes(b"not a gzip archive")
        with self.assertRaisesRegex(ValueError, r"invalid r:gz archive"):
            validate_archive(path)

    def test_archive_missing_ui_is_rejected(self) -> None:
        path = self.directory / "barelytics-php-v1.0.0.zip"
        make_zip(path, {"admin-ui/index.html": UI["admin-ui/index.html"]})
        with self.assertRaisesRegex(ValueError, r"missing shared admin UI files"):
            validate_archive(path)

    def test_sensitive_database_is_rejected(self) -> None:
        path = self.directory / "barelytics-node-v1.0.0.tgz"
        path.write_bytes(make_tar({**UI, "data/analytics.sqlite": b"local database"}))
        with self.assertRaisesRegex(ValueError, r"sensitive/local data file"):
            validate_archive(path)

    def test_private_key_content_is_rejected(self) -> None:
        path = self.directory / "barelytics-python-1.0.0.tar.gz"
        path.write_bytes(make_tar({**UI, "config.txt": b"-----BEGIN PRIVATE KEY-----\nsecret"}))
        with self.assertRaisesRegex(ValueError, r"possible secret"):
            validate_archive(path)

    def test_native_package_cannot_contain_php(self) -> None:
        path = self.directory / "barelytics-node-v1.0.0.tgz"
        path.write_bytes(make_tar({**UI, "src/accidental.php": b"<?php"}))
        with self.assertRaisesRegex(ValueError, r"prohibited PHP file"):
            validate_archive(path)


if __name__ == "__main__":
    unittest.main()
