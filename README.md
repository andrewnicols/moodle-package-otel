# Moodle OpenTelemetry Integration for Moodle

This is the OpenTelemetry integration for Moodle. It must be installed using composer, and requires the [OpenTelemetry PHP Extension](https://github.com/open-telemetry/opentelemetry-php-instrumentation/).

## Installation

Open Telemetry requires the installation of the [Open Telemetry PHP Extension](https://github.com/open-telemetry/opentelemetry-php-instrumentation).

After the installation of Moodle, and the Open Telemetry extension, this plugin can be installed using composer:

```shell
composer require moodlehq/moodle-package-otel
```

Exporters and Protocols must also be installed per your requirements, for example:

```shell
composer require open-telemetry/exporter-otlp
```

PHP Configuration will be required as described by your preferred exporter.

## Overview

Auto-instrumentation hooks are registered via composer, and spans will be automatically created for (`moodlelms`):

- Every access (root span), including for:
  - Web Requests
  - CLI Usage
- When a web request is made using the Moodle Routing engine:
  - App::handle() - update the root span with Routing-specific information
  - InvocationStrategyInterface - controller/action
  - RoutingMiddleware::performRouting - update the root span's name with either route name or pattern

Spans are also created for:

- Tasks (`moodlelms.cronlistener`) - for the processing of each
  - scheduled task; and
  - adhoc task.
- Moodle Events (`moodlelms.eventlistener`) - for the processing of Logging Events at:
  - time of event dispatch; and
  - time of bulk processing.
- Web Service Requests (`moodlelms.externalapilistener`) - one span per external function call.

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/languages/php/sdk/#configuration):

The core functionality and all child listeners can be disabled using:

```ini
OTEL_PHP_DISABLED_INSTRUMENTATIONS=moodlelms
```

To disable instrumentation for one of the groups of child listeners:

```ini
OTEL_PHP_DISABLED_INSTRUMENTATIONS=moodlelms.cronlistener
```

## Adding additional instrumentation

You can add other auto-instrumentation using composer.

If you wish to create Moodle-specific instrumentation you can either do so as a standard Open Telemetry instrumentation, or you can create a Moodle Package.

### Creating Moodle Open Telemetry Auto Instrumentation packages

To create your own page, you should:

- specify a package type of `moodle-package-otelhook`
- define your listeners with each listener implementing the `Moodlehq\MoodlePackageOtel\Instrumentation\MoodleListenerInterface` interface and optionally using the `Moodlehq\MoodlePackageOtel\Instrumentation\MoodleListenerTrait` trait
- define a `\Namespace\Instrumentation\ListenersDescriber` class which implements `\Moodlehq\MoodlePackageOtel\Instrumentation\ListenersDescriberInterface`
- define your listeners in the `ListenersDescriber` class
