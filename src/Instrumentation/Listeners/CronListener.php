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
 * Cron and task Listener for Moodle.
 *
 * @copyright Andrew Lyons <andrew@nicols.co.uk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class CronListener implements
    \Moodlehq\MoodlePackageOtel\Instrumentation\MoodleListenerInterface
{
    use \Moodlehq\MoodlePackageOtel\Instrumentation\MoodleListenerTrait;

    #[\Override]
    public function instrument(): void
    {
        $instrumentation = $this->instrumentation;

        hook(
            \core\cron::class,
            'run_inner_scheduled_task',
            pre: static function (...$args) use ($instrumentation): void {
                self::preScheduledTaskListener($instrumentation, ...$args);
            },
            post: static function (string $class, array $params,  $returnvalue, ?\Throwable $exception): void {
                self::endSpan([], $exception, null);
            },
        );
        hook(
            \core\cron::class,
            'run_inner_adhoc_task',
            pre: static function (...$args) use ($instrumentation): void {
                self::preAdhocTaskListener($instrumentation, ...$args);
            },
            post: static function (string $class, array $params,  $returnvalue, ?\Throwable $exception): void {
                self::endSpan([], $exception, null);
            },
        );
    }

    /**
     * Pre Listener handler for Scheduled Task execution.
     *
     * @param \core\cron $cron
     * @param array  $params    The parameters passed to the task, expected to contain the task as the first element.
     * @param string $class The class containing the method being hooked.
     * @param string $function  The function being hooked.
     * @param string|null    $filename    The file containing the code being hooked, if available.
     * @param int|null   $lineno The line number of the code being hooked, if available.
     */
    private static function preScheduledTaskListener(
        CachedInstrumentation $instrumentation,
        \core\cron|string $cron,
        array $params,
        string $class,
        string $function,
        ?string $filename,
        ?int $lineno,
    ): void {
        [$task] = $params;

        $parent = Context::getCurrent();
        $builder = $instrumentation->tracer()
            ->spanBuilder(sprintf('moodle.task.scheduled %s', get_class($task)))
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
            ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
            ->setAttribute('moodle.task.time_started', $task->get_timestarted())
            ->setAttribute('moodle.task.last_run_time', $task->get_last_run_time())
            ->setAttribute('moodle.task.next_run_time', $task->get_next_run_time())
            ->setAttribute('moodle.task.fail_delay', $task->get_fail_delay());

        $span = $builder->startSpan();
        $context = $span->storeInContext($parent);

        Context::storage()->attach($context);
    }

    /**
     * Pre Listener handler for Adhoc Task execution.
     *
     * @param \core\cron $cron  The cron instance running the task.
     * @param array  $params    The parameters passed to the task, expected to contain the task as the first element.
     * @param string $class The class containing the method being hooked.
     * @param string $function  The function being hooked.
     * @param string|null    $filename    The file containing the code being hooked, if available.
     * @param int|null   $lineno The line number of the code being hooked, if available.
     */
    private static function preAdhocTaskListener(
        CachedInstrumentation $instrumentation,
        \core\cron|string $cron,
        array $params,
        string $class,
        string $function,
        ?string $filename,
        ?int $lineno,
    ): void {
        [$task] = $params;
        $parent = Context::getCurrent();
        $builder = $instrumentation->tracer()
            ->spanBuilder(sprintf('moodle.task.adhoc %s', get_class($task)))
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
            ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
            ->setAttribute('moodle.task.id', $task->get_id())
            ->setAttribute('moodle.task.time_started', $task->get_timestarted())
            ->setAttribute('moodle.task.next_run_time', $task->get_next_run_time())
            ->setAttribute('moodle.task.fail_delay', $task->get_fail_delay())
            ->setAttribute('moodle.task.userid', $task->get_userid())
            ->setAttribute('moodle.task.attempts_available', $task->get_attempts_available())
            ->setAttribute('moodle.task.retry_until_success', $task->retry_until_success());

        $span = $builder->startSpan();
        $context = $span->storeInContext($parent);

        Context::storage()->attach($context);
    }
}
