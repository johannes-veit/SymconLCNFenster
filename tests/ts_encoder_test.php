<?php

declare(strict_types=1);

class IPSModuleStrict {}
require_once __DIR__ . '/../LCNWindow/module.php';

$reflection = new ReflectionClass(LCNWindow::class);
$module = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('BuildTSData');

$cases = [
    ['A', 3, 'K', 'K---00100000'],
    ['A', 3, 'L', 'L---00100000'],
    ['B', 1, 'K', '-K--10000000'],
    ['C', 4, 'L', '--L-00010000'],
    ['D', 8, 'K', '---K00000001'],
    ['D', 8, 'L', '---L00000001'],
];

foreach ($cases as [$table, $key, $command, $expected]) {
    $actual = $method->invoke($module, $table, $key, $command);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL TS $table$key/$command: $actual != $expected\n");
        exit(1);
    }
}

$thrown = false;
try {
    $method->invoke($module, 'A', 9, 'K');
} catch (Throwable) {
    $thrown = true;
}
if (!$thrown) {
    fwrite(STDERR, "FAIL invalid key was accepted\n");
    exit(1);
}

echo "OK ts_encoder_test\n";
