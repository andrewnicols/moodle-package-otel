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

use Moodlehq\MoodlePackageOtel\Instrumentation\Slim\CallableFormatter;
use Moodlehq\MoodlePackageOtel\Instrumentation\Slim\PsrResponsePropagationSetter;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\Contrib\Otlp\MetricExporterFactory;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Logs as LogAPI;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Propagation\TraceContextValidator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Otlp\LogsExporterFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SpanProcessorFactory;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use OpenTelemetry\SemConv\ResourceAttributes;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Interfaces\InvocationStrategyInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Middleware\RoutingMiddleware;
use Slim\Routing\RouteContext;
use Throwable;

/**
 * Instrument the Moodle product.
 *
 * This class is responsible for setting up the OpenTelemetry SDK and registering Listeners for other Moodle OpenTelemetry features.
 *
 * It also provides a root span for the current request, which other spans can be linked to.
 *
 * The root span is created in the `initialise` method, which is called from the `core\telemetry` class.
 * The span is ended in the `shutdown_handler` method, which is called from the `core\shutdown_manager` class.
 *
 * Other Moodle OpenTelemetry features can register Listeners by:
 * - using a Composer package type of `moodle-plugin-otelhook`;
 * - creating listeners which implement the `MoodleListenerInterface`;
 * - creating a describer which implements the `ListenersDescriberInterface` and returns the listeners.
 *
 * @copyright Andrew Lyons <andrew@nicols.co.uk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class MoodleInstrumentation
{
    /** @var string The name of the OpenTelemetry service */
    public const NAME = 'moodlelms';

    public const PACKAGE_NAME = 'moodlehq/moodle-package-otel';

    /** @var bool Whether the instrumentation has been initialised */
    protected static bool $initialised = false;

    /** @var SpanInterface|null The root span */
    protected static ?SpanInterface $rootSpan = null;

    /** @var ScopeInterface|null The root scope */
    protected static ?ScopeInterface $rootScope = null;

    /** @var CachedInstrumentation|null The cached instrumentation */
    protected static ?CachedInstrumentation $instrumentation = null;

    /**
     * Register all Moodle-related hooks.
     */
    public static function register(): void
    {
        self::registerMoodleListeners();
        self::registerRoutingListeners();
        self::registerLoggingListeners();
        self::registerListenersForPackage(self::PACKAGE_NAME);
        self::registerPluginListeners();
    }

    /**
     * Register listeners for Moodle core features.
     *
     * @return void
     */
    protected static function registerMoodleListeners(): void
    {
        hook(
            \core\shutdown_manager::class, // phpstan-ignore class.notFound
            'initialize',
            function (): void {
                self::getRootSpan();
            },
            null,
        );

        hook(
            \core\shutdown_manager::class, // phpstan-ignore class.notFound
            'shutdown_handler',
            null,
            function (): void {
                self::postShutdownHandler();
            }
        );
    }

    /**
     * Register listeners for logging Moodle Events.
     *
     * @return void
     */
    protected static function registerLoggingListeners(): void
    {
        if (Sdk::isInstrumentationDisabled('moodlelms.logging') === true) {
            return;
        }

        hook(
            \core\event\manager::class,
            'dispatch',
            null,
            function (
                $class,
                array $params,
                $returnvalue,
                ?\Throwable $throwable,
            ): void {
                /** @var \core\event\base $event */
                [$event] = $params;

                $eventDescription = $event->get_description();
                $eventData = $event->get_data();
                $timestamp = \DateTime::createFromFormat('U', (string) $eventData['timecreated']);

                $logRecord = (new LogAPI\LogRecord())
                    ->setTimestamp((int) $timestamp->format('Uu') * 1000)
                    ->setBody($eventDescription);

                foreach ($eventData as $key => $value) {
                    if (is_scalar($value) || (is_array($value) && count($value) < 10)) {
                        $logRecord->setAttribute("event.data.{$key}", $value);
                    }
                }
                foreach ($eventData as $key => $value) {
                    // if (isset($eventData[$key]) && (is_array(count($eventData[$key]) > 0) {
                    //     $logRecord->setAttribute($key, $eventData[$key]);
                    // }
                }

                self::getCachedInstrumentation()->logger()->emit($logRecord);
            },
        );
    }

    /**
     * Register listeners for Slim routing.
     *
     * This will update the root span with the route information, and create a child span for the route action/controller/callable.
     *
     * @return void
     */
    protected static function registerRoutingListeners(): void
    {
        // Note: Much of this is copied from the OpenTelemetry Slim auto-instrumentation.
        // Because we have already set up a root span for the request, we want to update that root span, not create a child span.
        // Unfortunately, the Slim auto-instrumentation is designed to create a new span for the routing.
        // It is also not written in such a way that we can pull parts of it, so we have to copy it and amend part of it.
        // The Slim Autoinstrumentation can be found at https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Slim
        // It is distributed under the Apache 2.0 License, which is compatible with our GPLv3 License.
        // The original license is available from: https://github.com/open-telemetry/opentelemetry-php-contrib/blob/main/LICENSE
        // All copyright and license notices in the original code are retained in this file.
        $instrumentation = self::getCachedInstrumentation();
        /**
         * requires extension >= 1.0.2beta2
         * @see https://github.com/open-telemetry/opentelemetry-php-instrumentation/pull/136
         */
        $otelVersion = phpversion('opentelemetry');
        $supportsResponsePropagation = $otelVersion !== false && version_compare($otelVersion, '1.0.2beta2') >= 0;

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            App::class,
            'handle',
            pre: static function (
                App $app,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ): array {
                $request = ($params[0] instanceof ServerRequestInterface) ? $params[0] : null;

                if ($request) {
                    $span = self::getRootSpan();
                    $span
                        ?->updateName(sprintf('%s', $request->getMethod()))
                        ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                        ->setAttribute(TraceAttributes::URL_FULL, $request->getUri()->__toString())
                        ->setAttribute(TraceAttributes::HTTP_SCHEME, $request->getUri()->getScheme())
                        ->setAttribute(TraceAttributes::URL_PATH, $request->getUri()->getPath())
                        ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                        ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                        ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
                    $request = $request->withAttribute(SpanInterface::class, $span);
                }
                return [$request];
            },
            post: static function (
                App $app,
                array $params,
                ?ResponseInterface $response,
                ?Throwable $exception,
            ) use ($supportsResponsePropagation): ?ResponseInterface {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return $response;
                }
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                if ($response) {
                    if ($response->getStatusCode() >= 400) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                    $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                    $span->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
                    $span->setAttribute(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE, $response->getHeaderLine('Content-Length'));

                    if ($supportsResponsePropagation) {
                        $prop = Globals::responsePropagator();
                        $prop->inject($response, PsrResponsePropagationSetter::instance(), $scope->context());
                    }
                }

                return $response;
            }
        );

        /**
         * Update root span's name after Slim routing, using either route name or method+pattern.
         * This relies upon the existence of a request attribute with key SpanInterface::class
         * and type SpanInterface which represents the root span, having been previously set
         * If routing fails (eg 404/not found), then the root span name will not be updated.
         *
         * @todo this can use LocalRootSpan (available since API 1.1.0)
         *
         * @psalm-suppress ArgumentTypeCoercion
         * @psalm-suppress UnusedFunctionCall
         */
        hook(
            RoutingMiddleware::class,
            'performRouting',
            pre: null,
            post: static function (RoutingMiddleware $middleware, array $params, ?ServerRequestInterface $request, ?Throwable $exception) {
                if ($exception || !$request) {
                    return;
                }
                $span = $request->getAttribute(SpanInterface::class);
                if (!$span instanceof SpanInterface) {
                    return;
                }
                $route = $request->getAttribute(RouteContext::ROUTE);
                if (!$route instanceof RouteInterface) {
                    return;
                }
                $span->setAttribute(HttpAttributes::HTTP_ROUTE, $route->getName() ?? $route->getPattern());
                $span->updateName(sprintf('%s %s', $request->getMethod(), $route->getName() ?? $route->getPattern()));
            }
        );

        /**
         * Create a span for Slim route's action/controller/callable
         *
         * @psalm-suppress ArgumentTypeCoercion
         * @psalm-suppress UnusedFunctionCall
         */
        hook(
            InvocationStrategyInterface::class,
            '__invoke',
            pre: static function (InvocationStrategyInterface $strategy, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $callable = $params[0];
                $name = CallableFormatter::format($callable);
                $builder = $instrumentation->tracer()->spanBuilder($name)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (InvocationStrategyInterface $strategy, array $params, ?ResponseInterface $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                $span->end();
            }
        );
    }

    /**
     * Initialise the telemetry manager.
     *
     * @return bool Whether the instrumentation was successfully initialised.
     */
    protected static function configureInstrumentation(): bool
    {
        if (static::$initialised) {
            return true;
        }

        static::$initialised = true;

        $resource = ResourceInfoFactory::emptyResource()->merge(ResourceInfo::create(Attributes::create([
            ResourceAttributes::SERVICE_NAMESPACE => self::NAME,
            ResourceAttributes::SERVICE_NAME => self::NAME,
            ResourceAttributes::SERVICE_VERSION => self::getMoodleVersion(),
        ])));

        $spanexporter = (new ExporterFactory())->create();
        $tracerprovider = TracerProvider::builder()
            ->addSpanProcessor(
                (new SpanProcessorFactory())->create($spanexporter)
            )
            ->setResource($resource)
            ->setSampler(new ParentBased(new AlwaysOnSampler()))
            ->build();

        $logexporter = (new LogsExporterFactory())->create();
        $loggerprovider = LoggerProvider::builder()
            ->setResource($resource)
            ->addLogRecordProcessor(
                new SimpleLogRecordProcessor($logexporter)
            )
            ->build();

        // Configure the Metric Exporter.
        $metricExporter = (new MetricExporterFactory())->create();

        $reader = new ExportingReader($metricExporter);
        $meterProvider = MeterProvider::builder()
            ->setResource($resource)
            ->addReader($reader)
            ->build();

        // Register all Providers and Propagators with the API Globals, so they can be accessed by the Listeners and other instrumentation.
        Sdk::builder()
            ->setTracerProvider($tracerprovider)
            ->setLoggerProvider($loggerprovider)
            ->setMeterProvider($meterProvider)
            ->setPropagator(TraceContextPropagator::getInstance())
            ->setAutoShutdown(true)
            ->buildAndRegisterGlobal();

        return true;
    }

    /**
     * Register Listeners for other Moodle OpenTelemetry features.
     *
     * @return void
     */
    protected static function registerPluginListeners(): void
    {
        $packages = \Composer\InstalledVersions::getInstalledPackagesByType('moodle-package-otelhook');
        foreach ($packages as $package) {
            self::registerListenersForPackage($package);
        }
    }

    /**
     * Register the listeners for a specific Composer package.
     *
     * @param  string $package The name of the package to register listeners for, in the format `vendor/package-name`.
     * @return void
     */
    protected static function registerListenersForPackage(string $package): void
    {
        $namespace = self::getNamespaceFromPackageName($package);
        $describer = sprintf('%s\\Instrumentation\\ListenersDescriber', $namespace);
        if (!class_exists($describer) || !is_a($describer, ListenersDescriberInterface::class, true)) {
            return;
        }

        $listeners = $describer::getListeners();
        foreach ($listeners as $listener) {
            if (!class_exists($listener) || !is_a($listener, MoodleListenerInterface::class, true)) {
                continue;
            }

            if (Sdk::isInstrumentationDisabled($listener::getName()) === true) {
                continue;
            }

            $listener::listen(self::getCachedInstrumentation());
        }
    }

    /**
     * Get the namespace for a given package name.
     *
     * @param  string $packageName The package name in the format `vendor/package-name`
     * @return string The namespace in the format `\Vendor\PackageName`
     */
    protected static function getNamespaceFromPackageName(string $packageName): string
    {
        [$vendor, $name] = array_pad(explode('/', $packageName, 2), 2, '');
        $vendor = ucfirst(strtolower($vendor));

        $nameParts = array_filter(explode('-', $name), fn($value) => strlen($value) > 0);
        $name = implode('', array_map(
            fn(string $p) => ucfirst(strtolower($p)),
            $nameParts
        ));

        return "\\{$vendor}\\{$name}";
    }

    /**
     * Shut down the telemetry manager, ending the request span.
     *
     * This method is called from the shutdown manager.
     * @internal
     */
    public static function postShutdownHandler(): void
    {
        if (self::$rootSpan === null) {
            return;
        }

        self::$rootScope->detach();

        self::$rootSpan->setAttributes(self::getPageAttributes());

        if (self::rootSpanHasRouteData() === false) {
            $statuscode = http_response_code();
            self::$rootSpan->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $statuscode);
            if ($statuscode >= 100 && $statuscode < 400) {
                self::$rootSpan->setStatus(StatusCode::STATUS_OK);
            } else if ($statuscode >= 400 && $statuscode < 600) {
                self::$rootSpan->setStatus(StatusCode::STATUS_ERROR);
            } else {
                self::$rootSpan->setStatus(StatusCode::STATUS_UNSET);
            }
        }

        if (function_exists('get_performance_info')) {
            // Add the performance info to the span.
            self::$rootSpan->addEvent(
                'moodle.shutdown_handler',
                array_filter(
                    get_performance_info(),
                    fn ($key): bool => $key !== 'txt' && $key !== 'html',
                    ARRAY_FILTER_USE_KEY,
                ),
            );
        } else {
            // Fallback for older PHP versions.
            self::$rootSpan->addEvent('moodle.shutdown_handler', ['message' => 'No performance info available']);
        }
        self::$rootSpan->end();

        self::$rootSpan = null;
        self::$rootScope = null;
    }

    /**
     * Whether the root span has route data already.
     *
     * @return bool
     */
    protected static function rootSpanHasRouteData(): bool
    {
        if (self::$rootSpan === null) {
            return false;
        }


        if (!(self::$rootSpan instanceof ReadWriteSpanInterface)) {
            return false;
        }

        $spanData = self::$rootSpan->toSpanData();
        $spanAttributes = $spanData->getAttributes();
        return $spanAttributes->has(HttpAttributes::HTTP_ROUTE);
    }

    /**
     * Get the page trace for the current request.
     *
     * @return null|SpanInterface
     */
    protected static function getRootSpan(): ?SpanInterface
    {
        if (!static::configureInstrumentation()) {
            return null;
        }

        if (self::$rootSpan === null) {
            if (php_sapi_name() === 'cli') {
                $span = self::getRootCliSpan();
            } else if (array_key_exists('REQUEST_METHOD', $_SERVER)) {
                $span = self::getRootHttpSpan();
            } else {
                $spanBuilder = self::getBuilder();
                $span = $spanBuilder->startSpan();
                // We don't have a request method, so we assume this is a page that is not being served via HTTP.
                $span->updateName('moodle.page');
            }

            self::$rootSpan = $span;
            self::$rootScope = $span->activate();
        }

        return self::$rootSpan;
    }

    /**
     * Get the spanBuilder to build the root span.
     *
     * @return SpanBuilderInterface
     */
    protected static function getBuilder(): SpanBuilderInterface
    {
        $parent = Context::getCurrent();
        return self::getCachedInstrumentation()->tracer()
            ->spanBuilder('moodle')
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_INTERNAL);
    }

    /**
     * Get the root CLI Span instance with attributes set.
     *
     * @return SpanInterface
     */
    protected static function getRootCliSpan(): SpanInterface
    {
        $spanBuilder = self::getBuilder();
        $spanBuilder->setSpanKind(SpanKind::KIND_INTERNAL);
        $span = $spanBuilder->startSpan();

        // CLI Spans are documented in the spec at
        // https://opentelemetry.io/docs/specs/semconv/cli/cli-spans/#execution-callee-spans.
        $process = $_SERVER['_'] ?? 'unknown';

        $span
            ->updateName(sprintf('moodle.cli %s', $_SERVER['argv'][0] ?? ''))
            ->setAttribute(TraceAttributes::PROCESS_EXECUTABLE_PATH, $process)
            ->setAttribute(TraceAttributes::PROCESS_COMMAND_ARGS, $_SERVER['argv'] ?? []);

        if ($pid = getmypid()) {
            // The process ID is available in CLI mode.
            $span->setAttribute(TraceAttributes::PROCESS_PID, $pid);
        }

        return $span;
    }

    /**
     * Get the root HTTP Span instance with attributes set.
     *
     * @return SpanInterface
     */
    protected static function getRootHttpSpan(): SpanInterface
    {
        $spanBuilder = self::getBuilder();
        $span = $spanBuilder->startSpan();

        // HTTP Server Spans are documented in the spec at
        // https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-server.

        $span
            ->updateName(sprintf('%s %s', $_SERVER['REQUEST_METHOD'], $_SERVER['PHP_SELF'] ?? ''))
            ->setAttribute(TraceAttributes::SERVICE_NAME, 'moodle')

            // The following attributes are required.
            ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $_SERVER['REQUEST_METHOD'] ?? '')
            ->setAttribute(TraceAttributes::HTTP_SCHEME, isset($_SERVER['HTTPS']) ? 'https' : 'http')
            ->setAttribute(TraceAttributes::URL_PATH, $_SERVER['PHP_SELF'] ?? '')

            // The following attributes are conditionally required.
            ->setAttribute(TraceAttributes::URL_QUERY, $_SERVER['QUERY_STRING'] ?? '')

            // The following attributes are recommended.
            ->setAttribute(
                TraceAttributes::NET_PROTOCOL_VERSION,
                $_SERVER['SERVER_PROTOCOL'] ? explode('/', $_SERVER['SERVER_PROTOCOL'])[1] : null,
            )
            ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $_SERVER['HTTP_USER_AGENT'] ?? '')

            // Other things.
            ->setAttribute(TraceAttributes::URL_FULL, $_SERVER['REQUEST_URI'] ?? '')
            ->setAttribute(TraceAttributes::HTTP_CLIENT_IP, $_SERVER['REMOTE_ADDR'] ?? '')

            // Link to parent page span if available.
            ->addLink(self::getLinkedSpan(getallheaders(), 'pageparent'));

        header("X-Trace-Id: {$span->getContext()->getTraceId()}");

        return $span;
    }


    /**
     * Set the standard trace properties for the current request.
     *
     * @return iterable<non-empty-string, int|float|string|null> The standard page attributes.
     */
    protected static function getPageAttributes(): iterable
    {
        global $CFG;

        return [
            TraceAttributes::SERVICE_VERSION => self::getMoodleVersion(),

            // HTTP Server Spans https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-server.
            // Note: In the context of HTTP server, server.address and server.port attributes
            // capture the original host name and port.
            // They are intended, whenever possible, to be the same on the client and server sides.
            TraceAttributes::SERVER_ADDRESS => $CFG->wwwroot,
        ];
    }

    /**
     * Get the CachedInstrumentation instance.
     *
     * @return CachedInstrumentation
     */
    protected static function getCachedInstrumentation(): CachedInstrumentation
    {
        if (!isset(self::$instrumentation)) {
            // Note: Do not use DI here. We want to be able to set up Telemetry as early as possible.
            self::$instrumentation = new CachedInstrumentation(
                'io.opentelemetry.contrib.php.moodle',
                self::getMoodleVersion(),
                'https://opentelemetry.io/schemas/1.38.0',
                [
                    TraceAttributes::SERVICE_NAME => 'moodle',
                ],
            );
        }

        return self::$instrumentation;
    }

    /**
     * Helper function to get the Moodle version.
     *
     * @return string
     */
    protected static function getMoodleVersion(): string
    {
        global $CFG;

        if (isset($CFG->version)) {
            return (string) $CFG->version;
        }

        if (isset($CFG->dirroot)) {
            require($CFG->dirroot . '/version.php');

            if (isset($version)) {
                return (string) $version;
            }
        }

        $packages = \Composer\InstalledVersions::getInstalledPackagesByType('moodle-core');

        if (empty($packages)) {
            $rootPackage = \Composer\InstalledVersions::getRootPackage();
            return $rootPackage['version'];
        }

        $moodlePackage = $packages[0];
        return \Composer\InstalledVersions::getPrettyVersion($moodlePackage) ?? 'unknown';
    }


    /**
     * Get a linked SpanContext from the given carrier and key.
     *
     * @param  array<string, mixed> $carrier The carrier to extract from.
     * @param  string $key The key to extract.
     * @return SpanContextInterface
     */
    protected static function getLinkedSpan(array $carrier, string $key): SpanContextInterface
    {
        $getter = ArrayAccessGetterSetter::getInstance();

        $parentid = $getter->get($carrier, $key);

        if ($parentid === null) {
            return SpanContext::getInvalid();
        }

        $pieces = explode('-', $parentid);

        if (count($pieces) < 4) {
            return SpanContext::getInvalid();
        }

        [$version, $traceid, $spanid, $traceflags] = $pieces;

        if (
            !TraceContextValidator::isValidTraceVersion($version)
            || !SpanContextValidator::isValidTraceId($traceid)
            || !SpanContextValidator::isValidSpanId($spanid)
            || !TraceContextValidator::isValidTraceFlag($traceflags)
        ) {
            return SpanContext::getInvalid();
        }

        // Return invalid if the trace version is not a future version but still has > 4 pieces.
        $versionisfuture = hexdec($version) > hexdec('00');
        if (count($pieces) > 4 && !$versionisfuture) {
            return SpanContext::getInvalid();
        }

        // Only the sampled flag is extracted from the trace flags (00000001).
        $convertedtraceflags = hexdec($traceflags);
        $issampled = ($convertedtraceflags & TraceFlags::SAMPLED) === TraceFlags::SAMPLED;

        // Only traceparent header is extracted. No tracestate.
        return SpanContext::createFromRemoteParent(
            $traceid,
            $spanid,
            $issampled ? TraceFlags::SAMPLED : TraceFlags::DEFAULT
        );
    }
}
