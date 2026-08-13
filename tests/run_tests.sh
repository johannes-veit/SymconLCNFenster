#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIR="$ROOT/tests"
php -l "$ROOT/LCNWindow/module.php" >/dev/null
php -l "$ROOT/LCNWindowGroup/module.php" >/dev/null
php "$DIR/ts_encoder_test.php"
php "$DIR/state_machine_test.php"
php "$DIR/repository_integrity_test.php"
php "$DIR/framework_surface_test.php"
php "$DIR/runtime_simulation_test.php"
php "$DIR/stable_window_unchanged_test.php"
php "$DIR/window_group_integrity_test.php"
php "$DIR/window_group_runtime_test.php"
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
root=Path(os.environ['ROOT_PATH'])
for rel, out in [('LCNWindow/module.html','/tmp/lcw_tile.js'), ('LCNWindowGroup/module.html','/tmp/lcwg_tile.js')]:
    html=(root/rel).read_text().replace('%%INITIAL_DATA%%','{}')
    scripts=re.findall(r'<script(?:\s[^>]*)?>(.*?)</script>', html, flags=re.S|re.I)
    inline='\n'.join(s for s in scripts if s.strip())
    Path(out).write_text(inline)
print('OK js_extract')
PY
node --check /tmp/lcw_tile.js
node --check /tmp/lcwg_tile.js
echo "ALL TESTS OK"
