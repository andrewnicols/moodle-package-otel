<?php

declare(strict_types=1);

use Moodlehq\MoodlePackageOtel\Instrumentation\MoodleInstrumentation;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(MoodleInstrumentation::NAME) === true) {
    return;
}

if (extension_loaded('opentelemetry') === false) {
    trigger_error(
        'The opentelemetry extension must be loaded in order to autoload the Moodle OpenTelemetry auto-instrumentation',
        E_USER_WARNING,
    );

    return;
}

MoodleInstrumentation::register();
