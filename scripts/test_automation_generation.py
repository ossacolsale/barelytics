import subprocess
import sys
import unittest
from pathlib import Path


class AutomationGenerationTests(unittest.TestCase):
    def test_all_runtime_classifiers_match_the_authoritative_rules(self):
        script = Path(__file__).with_name("generate-automation.py")
        subprocess.run([sys.executable, str(script), "--check"], check=True)
