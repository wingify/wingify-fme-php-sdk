<?php

/**
 * Copyright 2024-2026 Wingify Software Pvt. Ltd.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace wingify\Utils;

use wingify\Enums\EventEnum;
use wingify\Utils\NetworkUtil;
use wingify\Services\ServiceContainer;

class DebuggerServiceUtil {

    /**
     * Extracts only the required fields from a decision object.
     *
     * @param array $decisionObj The decision object to extract fields from
     * @return array An array containing only rId, rvId, eId and evId when present
     */
    public static function extractDecisionKeys($decisionObj = []) {
        $extractedKeys = [];

        if (isset($decisionObj['rolloutId'])) {
            $extractedKeys['rId'] = $decisionObj['rolloutId'];
        }

        if (isset($decisionObj['rolloutVariationId'])) {
            $extractedKeys['rvId'] = $decisionObj['rolloutVariationId'];
        }

        if (isset($decisionObj['experimentId'])) {
            $extractedKeys['eId'] = $decisionObj['experimentId'];
        }

        if (isset($decisionObj['experimentVariationId'])) {
            $extractedKeys['evId'] = $decisionObj['experimentVariationId'];
        }

        return $extractedKeys;
    }

    /**
     * Sends a debug event to the FME platform.
     *
     * @param array $eventProps The properties for the event.
     * @param ServiceContainer|null $serviceContainer Untyped for PHP 7.0 compat; avoids PHP 8.4 implicit-nullable deprecation.
     * @return void
     */
    public static function sendDebugEvent($eventProps = [], $serviceContainer = null)
    {
        // NetworkUtil must receive the caller's ServiceContainer to avoid SettingsService singleton bleed across instances.
        $networkUtil = new NetworkUtil($serviceContainer);

        $properties = $networkUtil->getEventsBaseProperties(EventEnum::DEBUGGER_EVENT, null, null);
        $payload = $networkUtil->getDebuggerEventPayload($eventProps);
        $networkUtil->sendEvent($properties, $payload, EventEnum::DEBUGGER_EVENT);
    }

    /**
     * @deprecated Use sendDebugEvent() instead.
     */
    public static function sendDebugEventToWingify($eventProps = [], $serviceContainer = null)
    {
        self::sendDebugEvent($eventProps, $serviceContainer);
    }
}

