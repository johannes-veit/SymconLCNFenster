<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$expected = [
    'LCNWindow/module.php'  => 'c5fd653f870de27cca939b1073e986470a5a2c46661dc3fde38844c72d347bdf',
    'LCNWindow/module.html' => 'b8b8a2a3070007908a6f26212761ed93f234893d45dbee5ee166a9b5fb1b9c1f',
    'LCNWindow/module.json' => 'b81e1cdd00b3d4d69dd015bd0d57dba8da052245373577e50125417da028b1ea',
    'LCNWindow/form.json'   => 'c0477b09cbd0b6a589c0f5dffbafebfd88fd4472377e9fdb1d2f66aea2fa2ce5',
];
foreach ($expected as $relative => $hash) {
    $actual = hash_file('sha256', $root . '/' . $relative);
    if ($actual !== $hash) {
        fwrite(STDERR, "FAIL stable 0.1.1 file changed: $relative\n");
        exit(1);
    }
}
echo "OK stable_window_unchanged_test\n";
