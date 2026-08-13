<?php

declare(strict_types=1);

class IPSModuleStrict {}
require_once __DIR__ . '/../LCNWindow/module.php';

$reflection = new ReflectionClass(LCNWindow::class);
$module = $reflection->newInstanceWithoutConstructor();
$resolve = $reflection->getMethod('ResolveStateFromRelays');

$cases = [
    // open relay, close relay, previous stable, expected visible, expected stable
    [false, false, 0, 0, 0],
    [true,  false, 0, 4, 2],
    [false, false, 2, 2, 2],
    [false, true,  2, 3, 1],
    [false, false, 1, 1, 1],
    [true,  false, 1, 4, 2],
    [true,  true,  2, 5, 2],
    [true,  true,  1, 5, 1],
];

foreach ($cases as $index => [$open, $close, $stable, $expectedState, $expectedStable]) {
    $actual = $resolve->invoke($module, $open, $close, $stable);
    if ($actual['state'] !== $expectedState || $actual['stable'] !== $expectedStable) {
        fwrite(STDERR, 'FAIL state case ' . $index . ': ' . json_encode($actual) . "\n");
        exit(1);
    }
}

echo "OK state_machine_test\n";
