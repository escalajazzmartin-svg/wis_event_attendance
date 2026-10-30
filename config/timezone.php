<?php

$configuredTimezone = getenv('WEAMMS_TIMEZONE');
$configuredTimezone = $configuredTimezone === false || $configuredTimezone === ''
    ? 'Asia/Manila'
    : $configuredTimezone;

if (!in_array($configuredTimezone, ['Asia/Manila', 'UTC'], true)) {
    throw new RuntimeException(
        'WEAMMS_TIMEZONE must be set to Asia/Manila or UTC.'
    );
}

define('WEAMMS_TIMEZONE', $configuredTimezone);
define(
    'WEAMMS_DATABASE_TIMEZONE',
    $configuredTimezone === 'UTC' ? '+00:00' : '+08:00'
);

date_default_timezone_set(WEAMMS_TIMEZONE);
