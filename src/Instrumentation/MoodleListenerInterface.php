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

/**
 * An Interface that all Listeners for the Moodle instrumentation should implement.
 *
 * This ensures that all Listeners have a consistent way to be instantiated and to implement their instrumentation logic.
 *
 * Most listeners will also use the MoodleListenerTrait to provide common functionality, but this is not required.
 *
 * @copyright Andrew Lyons <andrew@nicols.co.uk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface MoodleListenerInterface
{
    /**
     * Singleton method to create or return the instrumentation.
     *
     * @param  \OpenTelemetry\API\Instrumentation\CachedInstrumentation $instrumentation
     * @return MoodleListenerInterface
     */
    public static function listen(
        \OpenTelemetry\API\Instrumentation\CachedInstrumentation $instrumentation,
    ): MoodleListenerInterface;

    /**
     * Method to implement the instrumentation logic for the Listener.
     */
    public function instrument(): void;

    /**
     * Get the name of the Listener for span naming.
     *
     * @return string
     */
    public static function getName(): string;
}
