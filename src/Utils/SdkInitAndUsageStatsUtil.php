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
use wingify\Packages\Logger\Core\LogManager;

class SdkInitAndUsageStatsUtil
{
    /**
     * Sends an SDK init event to the FME platform. Triggered when init() completes successfully.
     *
     * @param int|null $settingsFetchTime Time taken to fetch settings in milliseconds
     * @param int|null $sdkInitTime Time taken to initialize the SDK in milliseconds
     */
    public static function sendSdkInitEvent($settingsFetchTime = null, $sdkInitTime = null, $serviceContainer = null)
    {
        $networkUtil = new NetworkUtil($serviceContainer);
        try {
            $properties = $networkUtil->getEventsBaseProperties(EventEnum::SDK_INIT);
        
            $payload = $networkUtil->getSdkInitEventPayload(EventEnum::SDK_INIT, $settingsFetchTime, $sdkInitTime);

            $networkUtil->sendEvent($properties, $payload, EventEnum::SDK_INIT);
        } catch (\Exception $e) {
            $serviceContainer->getLoggerService()->error('SDK_INIT_EVENT_ERROR', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Sends a usage stats event to the FME platform.
     * This event is triggered when the SDK is initialized.
     *
     * @param int $usageStatsAccountId The account ID for usage statistics
     */
    public static function sendSDKUsageStatsEvent($usageStatsAccountId, $serviceContainer = null)
    {
        $networkUtil = new NetworkUtil($serviceContainer);
        try {
            // create the query parameters
            $properties = $networkUtil->getEventsBaseProperties(EventEnum::USAGE_STATS, null, null, true, $usageStatsAccountId);

            // create the payload with required fields
            $payload = $networkUtil->getSDKUsageStatsEventPayload(EventEnum::USAGE_STATS, $usageStatsAccountId);

            // Send the constructed properties and payload as a POST request
            // send eventName in parameters so that we can enable retry for this event
            $networkUtil->sendEvent($properties, $payload, EventEnum::USAGE_STATS);
        } catch (\Exception $e) {
            if ($serviceContainer && $serviceContainer->getLoggerService()) {
                $serviceContainer->getLoggerService()->error('SDK_USAGE_STATS_EVENT_ERROR', ['error' => $e->getMessage()]);
            }
        }
    }
} 