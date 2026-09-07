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

namespace wingify\Services;

use wingify\Models\SettingsModel;
use wingify\Utils\InternalEventsSamplingUtil;

/**
 * Applies sampling rules for internal SDK events:
 * vwo_sdkUsageStats and sampled vwo_sdkDebug.
 */
class InternalEventsSamplingService
{
    /** @var callable Provides random values in [0, 1) for sampling checks; overridable in tests. */
    private $randomValueProvider;

    /**
     * Creates a sampling service using lcg_value() for sampling checks.
     *
     * @param callable|null $randomValueProvider Supplier returning a value in [0, 1)
     */
    public function __construct(callable $randomValueProvider = null)
    {
        $this->randomValueProvider = $randomValueProvider ?? function () {
            return lcg_value();
        };
    }

    /**
     * Determines whether the usage-stats event should be sent.
     * On server, always sends unless alwaysApplySampling.server is enabled.
     *
     * @param SettingsModel|null $settings Parsed settings from the server
     * @return bool true when the usage-stats event should be sent
     */
    public function shouldSendUsageStatsEvent($settings)
    {
        if (!InternalEventsSamplingUtil::shouldApplySampling($settings)) {
            return true;
        }

        $usageStatsSamplingPercent = InternalEventsSamplingUtil::getUsageStatsSamplingPercent($settings);
        $randomValueProvider = $this->randomValueProvider;

        return InternalEventsSamplingUtil::passesSamplingPercent(
            $usageStatsSamplingPercent,
            $randomValueProvider()
        );
    }

    /**
     * Determines whether a sampled debug event should be sent.
     * Only called for msg_t keys in the sampled set; always-send keys bypass this.
     * When alwaysApplySampling.server is false, sampled debug events are always sent.
     *
     * @param SettingsModel|null $settings Parsed settings from the server
     * @return bool true when the sampled debug event should be sent
     */
    public function shouldSendSampledDebugEvent($settings)
    {
        if (!InternalEventsSamplingUtil::shouldApplySampling($settings)) {
            return true;
        }

        $debugSamplingPercent = InternalEventsSamplingUtil::getDebugEventSamplingPercent($settings);
        $randomValueProvider = $this->randomValueProvider;

        return InternalEventsSamplingUtil::passesSamplingPercent(
            $debugSamplingPercent,
            $randomValueProvider()
        );
    }
}
