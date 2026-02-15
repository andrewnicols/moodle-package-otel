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

namespace Moodlehq\MoodlePackageOtel\Instrumentation\Listeners;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use function OpenTelemetry\Instrumentation\hook;

/**
 * Event system Instrumentation for Moodle.
 *
 * @copyright Andrew Lyons <andrew@nicols.co.uk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class EventsListener implements
    \Moodlehq\MoodlePackageOtel\Instrumentation\MoodleListenerInterface
{
    use \Moodlehq\MoodlePackageOtel\Instrumentation\MoodleListenerTrait;

    #[\Override]
    public function instrument(): void
    {
        $instrumentation = $this->instrumentation;

        hook(
            \core\event\manager::class,
            'dispatch',
            pre: static function (...$args) use ($instrumentation): void {
                self::preDispatch($instrumentation, ...$args);
            },
            post: static function (string $class, array $params,  $returnvalue, ?\Throwable $exception): void {
                self::endSpan([], $exception, null);
            },
        );

        hook(
            \core\event\manager::class,
            'process_buffers',
            pre: static function (...$args) use ($instrumentation): void {
                self::preProcessBuffers($instrumentation, ...$args);
            },
            post: static function (string $class, array $params,  $returnvalue, ?\Throwable $exception): void {
                self::endSpan([], $exception, null);
            },
        );
    }

    /**
     * Pre hook handler for Moodle events.
     *
     * @param \core\event\manager  $manager The event manager instance processing the buffers.
     * @param array  $params The parameters passed to the dispatch method, expected to contain the events being processed as the first element.
     * @param string $class The class containing the method being hooked.
     * @param string $function The function being hooked.
     * @param string|null  $filename The file containing the code being hooked, if available.
     * @param int|null   $lineno The line number of the code being hooked, if available.
     * @return void
     */
    private static function preDispatch(
        CachedInstrumentation $instrumentation,
        \core\event\manager|string $manager,
        array $params,
        string $class,
        string $function,
        ?string $filename,
        ?int $lineno,
    ): void {
        [$task] = $params;

        $parent = Context::getCurrent();
        $builder = $instrumentation->tracer()
            ->spanBuilder(sprintf('moodle.event %s', get_class($task)))
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
            ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);

        $span = $builder->startSpan();
        $context = $span->storeInContext($parent);

        Context::storage()->attach($context);
    }

    /**
     * Pre hook handler for Buffer processing.
     *
     * @param \core\event\manager  $manager The event manager instance processing the buffers.
     * @param array  $params The parameters passed to the process_buffers method, expected to contain the events being processed as the first element.
     * @param string $class The class containing the method being hooked.
     * @param string $function The function being hooked.
     * @param string|null  $filename The file containing the code being hooked, if available.
     * @param int|null   $lineno The line number of the code being hooked, if available.
     * @return void
     */
    private static function preProcessBuffers(
        CachedInstrumentation $instrumentation,
        \core\event\manager|string $manager,
        array $params,
        string $class,
        string $function,
        ?string $filename,
        ?int $lineno,
    ): void {
        $parent = Context::getCurrent();
        $builder = $instrumentation->tracer()
            ->spanBuilder('moodle.event.process_buffers')
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
            ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);

        $span = $builder->startSpan();
        $context = $span->storeInContext($parent);

        Context::storage()->attach($context);
    }
}
