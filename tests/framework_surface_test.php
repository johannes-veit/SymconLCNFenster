<?php

declare(strict_types=1);

$module = file_get_contents(dirname(__DIR__) . '/LCNWindow/module.php');
if ($module === false) {
    fwrite(STDERR, "FAIL module.php missing\n");
    exit(1);
}

// ResetReferences is module-owned. It must never be assumed to exist in IPSModuleStrict.
if (!preg_match('/private\\s+function\\s+ResetReferences\\s*\\(\\s*\\)\\s*:\\s*void/', $module)) {
    fwrite(STDERR, "FAIL ResetReferences implementation missing\n");
    exit(1);
}
foreach (["GetReferenceList()", "UnregisterReference(\$referenceID)", "RegisterReference(\$sendModule)"] as $needle) {
    if (!str_contains($module, $needle)) {
        fwrite(STDERR, "FAIL reference API invariant missing: $needle\n");
        exit(1);
    }
}

// Ensure ApplyChanges calls only the module-owned helper, so a missing helper cannot hide in a test stub again.
$applyStart = strpos($module, 'public function ApplyChanges(): void');
$nextMethod = strpos($module, 'public function MessageSink', $applyStart ?: 0);
$apply = ($applyStart !== false && $nextMethod !== false) ? substr($module, $applyStart, $nextMethod - $applyStart) : '';
if ($apply === '' || !str_contains($apply, '$this->ResetReferences();')) {
    fwrite(STDERR, "FAIL ApplyChanges does not rebuild references\n");
    exit(1);
}

echo "OK framework_surface_test\n";
