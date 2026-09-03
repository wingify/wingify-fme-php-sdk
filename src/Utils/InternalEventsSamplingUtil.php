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
use wingify\Constants\SampledDebugErrorTemplateKeys;
use wingify\Models\SettingsModel;

/**
 * Utility methods for reading internal event sampling configuration from settings
 * and evaluating sampling decisions at runtime (server-side PHP SDK).
 */
final class InternalEventsSamplingUtil
{
    private function __construct()
    {
    }

    /**
     * Returns the default server sampling percentage when settings omit the value.
     *
     * @return float Default sampling percentage (10%)
     */
    public static function getDefaultSamplingPercent()
    {
        return Constants::INTERNAL_EVENTS_DEFAULT_SAMPLING_PERCENT_SERVER;
    }

    /**
     * Normalizes a sampling value to a valid percentage in the range [0, 100].
     * Falls back to the server default when the value is missing or invalid.
     *
     * @param float|null $samplingValue Raw value from settings
     * @return float Valid sampling percentage between 0 and 100
     */
    public static function normalizeSamplingPercent($samplingValue)
    {
        if ($samplingValue !== null && $samplingValue >= 0 && $samplingValue <= 100) {
            return (float) $samplingValue;
        }

        return self::getDefaultSamplingPercent();
    }

    /**
     * Reads the server sampling percentage from a runtime sampling config object.
     *
     * @param object|null $runtimeSamplingConfig Nested config (e.g. sampling.usage)
     * @return float Sampling percentage for the server runtime
     */
    public static function getRuntimeSamplingPercent($runtimeSamplingConfig)
    {
        if ($runtimeSamplingConfig === null) {
            return self::getDefaultSamplingPercent();
        }

        $server = isset($runtimeSamplingConfig->server) ? $runtimeSamplingConfig->server : null;

        return self::normalizeSamplingPercent($server);
    }

    /**
     * Reads the usage-stats sampling percentage for the server runtime from settings.
     *
     * @param SettingsModel|null $settings Parsed settings from the server
     * @return float Configured usage-stats sampling percentage (0–100)
     */
    public static function getUsageStatsSamplingPercent($settings)
    {
        // get sampling from settings
        $sampling = $settings !== null ? $settings->getSampling() : null;
        $usageSampling = $sampling !== null && isset($sampling->usage) ? $sampling->usage : null;

        return self::getRuntimeSamplingPercent($usageSampling);
    }

    /**
     * Reads the debug-event sampling percentage for the server runtime from settings.
     *
     * @param SettingsModel|null $settings Parsed settings from the server
     * @return float Configured debug sampling percentage (0–100)
     */
    public static function getDebugEventSamplingPercent($settings)
    {
        $sampling = $settings !== null ? $settings->getSampling() : null;
        $debugSampling = $sampling !== null && isset($sampling->debug) ? $sampling->debug : null;

        return self::getRuntimeSamplingPercent($debugSampling);
    }

    /**
     * Determines whether sampling should be evaluated before send.
     * Controlled by alwaysApplySampling.server; defaults to false.
     * When false, usage-stats and sampled debug events are always sent.
     *
     * @param SettingsModel|null $settings Parsed settings from the server
     * @return bool true when a sampling check is required before sending
     */
    public static function shouldApplySampling($settings)
    {
        if ($settings === null) {
            return Constants::INTERNAL_EVENTS_DEFAULT_ALWAYS_APPLY_SAMPLING;
        }

        $alwaysApplySampling = $settings->getAlwaysApplySampling();
        if ($alwaysApplySampling === null || !isset($alwaysApplySampling->server)) {
            return Constants::INTERNAL_EVENTS_DEFAULT_ALWAYS_APPLY_SAMPLING;
        }

        return (bool) $alwaysApplySampling->server;
    }

    /**
     * Evaluates whether an event qualifies under the configured sampling percentage.
     *
     * @param float $samplingPercent Configured sampling percentage (0–100)
     * @param float $randomValue Random value in the range [0, 1)
     * @return bool true when the random draw falls within the sampling threshold
     */
    public static function passesSamplingPercent($samplingPercent, $randomValue)
    {
        // Map random [0,1) to integer percent bucket [0,100]
        $normalizedRandomPercent = (int) floor($randomValue * 101);

        return $normalizedRandomPercent <= $samplingPercent;
    }

    /**
     * Checks whether a debug error template key is in the sampled set.
     *
     * @param string|null $messageTemplateKey The msg_t value from the debug event
     * @return bool true when the key requires sampling before send
     */
    public static function isSampledDebugErrorTemplateKey($messageTemplateKey)
    {
        return SampledDebugErrorTemplateKeys::contains($messageTemplateKey);
    }
}
