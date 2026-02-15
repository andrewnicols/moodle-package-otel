<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace Moodlehq\MoodlePackageOtel\Instrumentation;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use ReflectionClass;

/**
 * Helper trait for Moodle Listeners.
 *
 * @copyright Andrew Lyons <andrew@nicols.co.uk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait MoodleListenerTrait
{
    /** @var MoodleListenerInterface The singleton instance of the Listener */
    private static MoodleListenerInterface $instance;

    /**
     * Protected constructor to prevent non-singleton usage.
     *
     * @param CachedInstrumentation $instrumentation
     */
    final protected function __construct(
        /** @var CachedInstrumentation The instrumentation instance to use */
        protected CachedInstrumentation $instrumentation,
    ) {
        $this->instrument();
    }

    #[\Override]
    public static function listen(CachedInstrumentation $instrumentation): MoodleListenerInterface
    {
        if (!isset(self::$instance)) {
            self::$instance = new static($instrumentation);
        }

        return self::$instance;
    }

    #[\Override]
    public static function getName(): string
    {
        return 'moodle.' . strtolower((new ReflectionClass(static::class))->getShortName());
    }

    /**
     * End the span.
     *
     * @param iterable<string, array|bool|float|int|string|null>|null $attributes  And attributes to set on the span before ending it.
     * @phpstan-param iterable<non-empty-string, bool|float|int|string|null|array<array-key, mixed>>|null $attributes
     * @param \Throwable|null $exception   Any exception to record on the span before ending it.
     * @param string|null $errorstatus Any error status to set on the span before ending it.
     */
    protected static function endSpan(
        ?iterable $attributes,
        ?\Throwable $exception,
        ?string $errorstatus,
    ): void {
        // Fetch the current span.
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }

        // Detach the scope to end the span.
        $scope->detach();
        $span = Span::fromContext($scope->context());

        // Set any attributes, status or exception on the span before ending it.
        if ($attributes) {
            $span->setAttributes($attributes);
        }

        if ($errorstatus !== null) {
            $span->setAttribute(TraceAttributes::EXCEPTION_MESSAGE, $errorstatus);
            $span->setStatus(StatusCode::STATUS_ERROR, $errorstatus);
        }

        if ($exception) {
            $span->recordException($exception);
            $span->setAttribute(TraceAttributes::EXCEPTION_TYPE, $exception::class);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $span->end();
    }
}
