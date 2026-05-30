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

use wingify\Constants\Constants;
use wingify\Enums\HostProfileEnum;

class LogPrefixUtil
{
    /**
     * Resolves the log line prefix: customer logger.prefix first, then host profile default.
     */
    public static function resolveDefaultPrefix(array $options): string
    {
        if (isset($options['logger']['prefix']) && is_string($options['logger']['prefix']) && trim($options['logger']['prefix']) !== '') {
            return $options['logger']['prefix'];
        }

        $profile = $options['hostProfile'] ?? HostProfileEnum::WINGIFY;

        return $profile === HostProfileEnum::VWO
            ? Constants::LOG_PREFIX_VWO
            : Constants::LOG_PREFIX_WINGIFY;
    }

    /**
     * Placeholders for log message templates: brand (e.g. VWO) and logPrefix (e.g. VWO-SDK).
     *
     * @return array{brand: string, logPrefix: string}
     */
    public static function resolveBrandDisplayName($hostProfile = null): array
    {
        $profile = $hostProfile ?? HostProfileEnum::WINGIFY;
        $logPrefix = $profile === HostProfileEnum::VWO
            ? Constants::LOG_PREFIX_VWO
            : Constants::LOG_PREFIX_WINGIFY;

        $parts = explode('-', $logPrefix, 2);

        return [
            'brand' => $parts[0],
            'logPrefix' => $logPrefix,
        ];
    }

    /**
     * Resolves the logger display name: customer logger.name first, then host profile default.
     */
    public static function resolveLoggerName(array $options): string
    {
        if (isset($options['logger']['name']) && is_string($options['logger']['name']) && trim($options['logger']['name']) !== '') {
            return $options['logger']['name'];
        }

        $profile = $options['hostProfile'] ?? HostProfileEnum::WINGIFY;

        return $profile === HostProfileEnum::VWO
            ? Constants::LOGGER_NAME_VWO
            : Constants::LOGGER_NAME_WINGIFY;
    }

    /**
     * Builds logger config with profile-based defaults when prefix/name are not set by the customer.
     */
    public static function buildLoggerConfig(array $options): array
    {
        $loggerConfig = isset($options['logger']) && is_array($options['logger']) ? $options['logger'] : [];

        if (!isset($loggerConfig['prefix']) || !is_string($loggerConfig['prefix']) || trim($loggerConfig['prefix']) === '') {
            $loggerConfig['prefix'] = self::resolveDefaultPrefix($options);
        }

        if (!isset($loggerConfig['name']) || !is_string($loggerConfig['name']) || trim($loggerConfig['name']) === '') {
            $loggerConfig['name'] = self::resolveLoggerName($options);
        }

        return $loggerConfig;
    }
}
