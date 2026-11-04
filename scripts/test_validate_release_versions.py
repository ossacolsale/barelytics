"""Release tags must match every distributable, including PHP."""
from contextlib import redirect_stdout
from io import StringIO
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest

from validate_release_versions import main, versions


class ReleaseVersionTests(unittest.TestCase):
    def setUp(self):
        self.directory = TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.files = {
            'public/barelytics/src/Barelytics.php': "<?php const APPLICATION_VERSION = '1.2.0';",
            'packages/node/package.json': '{"version":"1.2.0"}',
            'packages/python/pyproject.toml': '[project]\nversion="1.2.0"\n',
            'packages/dotnet/Barelytics/Barelytics.csproj': '<Project><PropertyGroup><Version>1.2.0</Version></PropertyGroup></Project>',
            'packages/java/pom.xml': '<project xmlns="http://maven.apache.org/POM/4.0.0"><version>1.2.0</version></project>',
            'packages/ruby/barelytics.gemspec': "spec.version = '1.2.0'",
            'integrations/wordpress/barelytics/barelytics.php': '<?php\n/*\n * Version: 1.2.0\n */',
        }
        for name, content in self.files.items():
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content)

    def test_tag_matches_all_seven_distributables(self):
        self.assertEqual(len(versions(self.root)), 7)
        with redirect_stdout(StringIO()):
            main('v1.2.0', self.root)

    def test_php_runtime_mismatch_blocks_release(self):
        (self.root / 'public/barelytics/src/Barelytics.php').write_text("<?php const APPLICATION_VERSION = '1.1.0';")
        with self.assertRaisesRegex(ValueError, 'PHP=1.1.0'):
            main('v1.2.0', self.root)

    def test_missing_metadata_is_not_silently_ignored(self):
        (self.root / 'public/barelytics/src/Barelytics.php').write_text('<?php // missing version')
        with self.assertRaisesRegex(ValueError, 'Missing.*PHP'):
            versions(self.root)
        (self.root / 'public/barelytics/src/Barelytics.php').write_text(self.files['public/barelytics/src/Barelytics.php'])
        (self.root / 'packages/dotnet/Barelytics/Barelytics.csproj').write_text('<Project/>')
        with self.assertRaisesRegex(ValueError, 'Missing.*.NET'):
            versions(self.root)

    def test_native_or_plugin_mismatch_still_blocks_release(self):
        (self.root / 'integrations/wordpress/barelytics/barelytics.php').write_text('<?php\n/*\n * Version: 1.1.0\n */')
        with self.assertRaisesRegex(ValueError, 'WordPress=1.1.0'):
            main('v1.2.0', self.root)


if __name__ == '__main__':
    unittest.main()
