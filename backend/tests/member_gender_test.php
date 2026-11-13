<?php

require_once __DIR__ . '/../config/member_gender.php';

$cases = [
    'blank gender stays optional' => ['', null],
    'null gender stays optional' => [null, null],
    'male value is normalized' => ['male', 'Male'],
    'female value is normalized' => ['Female', 'Female'],
    'prefer not to say accepts underscore input' => ['prefer_not_to_say', 'Prefer not to say'],
];

$failures = [];
foreach ($cases as $name => [$input, $expected]) {
    if (normalizeOptionalMemberGender($input) !== $expected) {
        $failures[] = $name;
    }
}

foreach (['Other', 'Male/Female', 1, []] as $input) {
    try {
        normalizeOptionalMemberGender($input);
        $failures[] = 'invalid gender is rejected';
    } catch (InvalidArgumentException $e) {
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Passed ' . (count($cases) + 4) . ' optional gender validation cases.' . PHP_EOL;
