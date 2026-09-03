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

namespace wingify;

use PHPUnit\Framework\TestCase;
use wingify\Constants\Constants;
use wingify\Models\SettingsModel;
use wingify\Services\InternalEventsSamplingService;
use wingify\Utils\InternalEventsSamplingUtil;

/**
 * Unit tests for internal SDK event sampling.
 *
 * Covers two layers:
 * - InternalEventsSamplingUtil — reads DaCDN sampling config and evaluates pure sampling math
 * - InternalEventsSamplingService — applies send/drop decisions for usage-stats and sampled debug events
 *
 * Sampling config shape from settings:
 * {
 *   "sampling": {
 *     "usage": { "server": 20 },
 *     "debug": { "server": 50 }
 *   },
 *   "alwaysApplySampling": { "server": true }
 * }
 *
 * On the PHP server SDK, only server runtime values are consumed at send time.
 */
class InternalEventsSamplingServiceTest extends TestCase
{
    /** @var SettingsModel Reusable settings with usage=20%, debug=50%, alwaysApplySampling.server=true */
    private $mockSamplingSettings;

    protected function setUp(): void
    {
        $this->mockSamplingSettings = $this->createMockSamplingSettings();
    }

    /**
     * Builds a representative SettingsModel for sampling tests.
     */
    private function createMockSamplingSettings()
    {
        $settingsObject = (object) [
            'sampling' => (object) [
                'usage' => (object) ['server' => 20.0],
                'debug' => (object) ['server' => 50.0],
            ],
            'alwaysApplySampling' => (object) ['server' => true],
            'sdkMetaInfo' => (object) ['wasInitializedEarlier' => false],
        ];

        return new SettingsModel($settingsObject);
    }

    /**
     * Builds settings with custom sampling and alwaysApplySampling config.
     *
     * @param object|null $alwaysApplySampling
     * @param object|null $sampling
     * @return SettingsModel
     */
    private function createSettingsWithSampling($alwaysApplySampling, $sampling)
    {
        $settingsObject = (object) [
            'alwaysApplySampling' => $alwaysApplySampling,
            'sampling' => $sampling,
        ];

        return new SettingsModel($settingsObject);
    }

    private function assertEqualsDouble($expected, $actual)
    {
        $this->assertTrue(abs($expected - $actual) < 0.0001, "Expected $expected but got $actual");
    }

    // --- InternalEventsSamplingUtil: defaults and config parsing ---

    public function testServerDefaultSamplingIsUsedWhenValueMissing()
    {
        // Default server sampling percent is 10 when config is missing or out of range [0, 100]
        $this->assertEqualsDouble(
            Constants::INTERNAL_EVENTS_DEFAULT_SAMPLING_PERCENT_SERVER,
            InternalEventsSamplingUtil::getDefaultSamplingPercent()
        );
        $this->assertEqualsDouble(10, InternalEventsSamplingUtil::normalizeSamplingPercent(null));
        $this->assertEqualsDouble(10, InternalEventsSamplingUtil::normalizeSamplingPercent(150.0));
    }

    public function testRuntimeDefaultsAreUsedWhenSamplingAbsent()
    {
        // Empty settings should fall back to the server default for both usage and debug categories
        $emptySettings = new SettingsModel((object) []);
        $this->assertEqualsDouble(10, InternalEventsSamplingUtil::getUsageStatsSamplingPercent($emptySettings));
        $this->assertEqualsDouble(10, InternalEventsSamplingUtil::getDebugEventSamplingPercent($emptySettings));
    }

    public function testUsageStatsSamplingIsReadForServerRuntime()
    {
        $this->assertEqualsDouble(20, InternalEventsSamplingUtil::getUsageStatsSamplingPercent($this->mockSamplingSettings));
    }

    public function testAlwaysApplySamplingServerIsReadFromSettings()
    {
        $this->assertTrue(InternalEventsSamplingUtil::shouldApplySampling($this->mockSamplingSettings));
        // Missing alwaysApplySampling defaults to false
        $this->assertFalse(InternalEventsSamplingUtil::shouldApplySampling(new SettingsModel((object) [])));
    }

    // --- InternalEventsSamplingUtil: sampling math and debug key classification ---

    public function testSamplingPassesWithinThreshold()
    {
        // 0.1 maps to bucket 10, which is within a 20% threshold
        $this->assertTrue(InternalEventsSamplingUtil::passesSamplingPercent(20, 0.1));
    }

    public function testSamplingFailsAboveThreshold()
    {
        // 0.99 maps to bucket 99, which exceeds a 20% threshold
        $this->assertFalse(InternalEventsSamplingUtil::passesSamplingPercent(20, 0.99));
    }

    public function testSampledDebugKeysAreDetected()
    {
        $this->assertTrue(InternalEventsSamplingUtil::isSampledDebugErrorTemplateKey('EVENT_NOT_FOUND'));
        $this->assertTrue(InternalEventsSamplingUtil::isSampledDebugErrorTemplateKey('FEATURE_NOT_FOUND'));
        $this->assertTrue(InternalEventsSamplingUtil::isSampledDebugErrorTemplateKey('FEATURE_NOT_FOUND_WITH_ID'));
        $this->assertTrue(InternalEventsSamplingUtil::isSampledDebugErrorTemplateKey('INVALID_OPTIONS'));
    }

    public function testNonSampledDebugKeysAreNotDetected()
    {
        // Keys outside the sampled set are always sent (ALWAYS_SEND)
        $this->assertFalse(InternalEventsSamplingUtil::isSampledDebugErrorTemplateKey('NETWORK_CALL_FAILED'));
        $this->assertFalse(InternalEventsSamplingUtil::isSampledDebugErrorTemplateKey('EXECUTION_FAILED'));
        $this->assertFalse(InternalEventsSamplingUtil::isSampledDebugErrorTemplateKey(''));
    }

    // --- InternalEventsSamplingService: usage-stats send decisions ---

    public function testUsageStatsAlwaysSendWhenAlwaysApplySamplingFalse()
    {
        // Fixed random value; sampling gate is bypassed entirely when alwaysApplySampling.server is false
        $samplingService = new InternalEventsSamplingService(function () {
            return 0.0;
        });

        // Even with 0% configured, event should still send because sampling is not applied
        $settings = $this->createSettingsWithSampling(
            (object) ['server' => false],
            (object) ['usage' => (object) ['server' => 0.0]]
        );

        $this->assertTrue($samplingService->shouldSendUsageStatsEvent($settings));
    }

    public function testUsageStatsSamplingAppliesWhenAlwaysApplySamplingTrue()
    {
        // random=1.0 always fails sampling; with 0% usage threshold the event is dropped
        $samplingService = new InternalEventsSamplingService(function () {
            return 1.0;
        });

        $settings = $this->createSettingsWithSampling(
            (object) ['server' => true],
            (object) ['usage' => (object) ['server' => 0.0]]
        );

        $this->assertFalse($samplingService->shouldSendUsageStatsEvent($settings));
    }

    // --- InternalEventsSamplingService: sampled debug send decisions ---

    public function testSampledDebugEventsAlwaysSendWhenAlwaysApplySamplingFalse()
    {
        $samplingService = new InternalEventsSamplingService(function () {
            return 1.0;
        });

        $settings = $this->createSettingsWithSampling(
            (object) ['server' => false],
            (object) ['debug' => (object) ['server' => 0.0]]
        );

        $this->assertTrue($samplingService->shouldSendSampledDebugEvent($settings));
    }

    public function testSampledDebugEventsApplyDebugSamplingWhenAlwaysApplySamplingTrue()
    {
        // mockSamplingSettings has debug.server=50%, alwaysApplySampling.server=true; random=0.0 always passes
        $samplingService = new InternalEventsSamplingService(function () {
            return 0.0;
        });
        $this->assertTrue($samplingService->shouldSendSampledDebugEvent($this->mockSamplingSettings));
    }

    public function testSampledDebugEventsBlockedWhenAlwaysApplySamplingTrueAndSamplingZero()
    {
        $samplingService = new InternalEventsSamplingService(function () {
            return 1.0;
        });

        $settings = $this->createSettingsWithSampling(
            (object) ['server' => true],
            (object) ['debug' => (object) ['server' => 0.0]]
        );

        $this->assertFalse($samplingService->shouldSendSampledDebugEvent($settings));
    }
}
