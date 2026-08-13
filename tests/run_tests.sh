#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIR="$ROOT/tests"
php -l "$ROOT/LCNWindow/module.php" >/dev/null
php "$DIR/ts_encoder_test.php"
php "$DIR/state_machine_test.php"
php "$DIR/repository_integrity_test.php"
php "$DIR/framework_surface_test.php"
php "$DIR/runtime_simulation_test.php"
ROOT_PATH="$ROOT" python3 - <<'PY'
import json, os
from pathlib import Path
root=Path(os.environ['ROOT_PATH'])
for p in root.rglob('*.json'):
    json.loads(p.read_text())
print('OK json_parse')
PY
ROOT_PATH="$ROOT" python3 - <<'PY'
from pathlib import Path
import re, os
html=(Path(os.environ['ROOT_PATH'])/'LCNWindow/module.html').read_text().replace('%%INITIAL_DATA%%','{}')
scripts=re.findall(r'<script(?:\s[^>]*)?>(.*?)</script>', html, flags=re.S|re.I)
inline='\n'.join(s for s in scripts if s.strip())
Path('/tmp/lcw_tile.js').write_text(inline)
PY
node --check /tmp/lcw_tile.js
echo "ALL TESTS OK"
