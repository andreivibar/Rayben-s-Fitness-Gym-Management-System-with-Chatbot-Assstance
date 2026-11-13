<?php

const MEMBER_GENDER_VALUES = ['Male', 'Female', 'Prefer not to say'];

function normalizeOptionalMemberGender($value): ?string
{
    if ($value === null) {
        return null;
    }
    if (!is_string($value)) {
        throw new InvalidArgumentException('Gender must be a string value.');
    }

    $gender = trim($value);
    if ($gender === '') {
        return null;
    }

    $normalized = strtolower(str_replace('_', ' ', $gender));
    foreach (MEMBER_GENDER_VALUES as $allowedGender) {
        if ($normalized === strtolower($allowedGender)) {
            return $allowedGender;
        }
    }

    throw new InvalidArgumentException('Gender must be one of: Male, Female, Prefer not to say.');
}
