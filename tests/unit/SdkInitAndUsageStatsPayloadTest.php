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
use wingify\Enums\EventEnum;
use wingify\Packages\Logger\Core\LogManager;
use wingify\Services\LoggerService;
use wingify\Services\ServiceContainer;
use wingify\Services\SettingsService;
use wingify\Utils\NetworkUtil;
use wingify\Utils\UsageStatsUtil;

/**
 * Unit tests for SDK init and usage-stats event payloads.
 *
 * Covers the observability change that keeps vwo_fmeSdkInit slim
 * and moves timings + initConfig onto vwo_sdkUsageStats.
 */
class SdkInitAndUsageStatsPayloadTest extends TestCase
{
    private const SDK_KEY = 'test-sdk-key';
    private const ACCOUNT_ID = 12345;
    private const USAGE_STATS_ACCOUNT_ID = 99999;

    /** @var NetworkUtil */
    private $networkUtil;

    /** Builds a service container and NetworkUtil instance for each test. */
    protected function setUp(): void
    {
        $options = [
            'sdkKey' => self::SDK_KEY,
            'accountId' => self::ACCOUNT_ID,
        ];

        $logManager = new LogManager([]);
        $loggerService = new LoggerService($logManager);
        $settingsService = new SettingsService($options, $logManager, $loggerService);

        $serviceContainer = new ServiceContainer($options);
        $serviceContainer->setLogManager($logManager);
        $serviceContainer->setLoggerService($loggerService);
        $serviceContainer->setSettingsService($settingsService);

        UsageStatsUtil::getInstance()->setUsageStats($options);
        $this->networkUtil = new NetworkUtil($serviceContainer);
    }

    /** SDK init payload should only include isSDKInitialized in data. */
    public function testSdkInitPayloadContainsOnlyIsSdkInitialized()
    {
        $payload = $this->networkUtil->getSdkInitEventPayload(EventEnum::SDK_INIT);
        $data = $this->getEventData($payload);

        $this->assertTrue($data['isSDKInitialized']);
        $this->assertArrayNotHasKey('settingsFetchTime', $data);
        $this->assertArrayNotHasKey('sdkInitTime', $data);
        $this->assertArrayNotHasKey('initConfig', $data);
        $this->assertCount(1, $data);
    }

    /** Usage stats payload should include timings and initConfig in data. */
    public function testUsageStatsPayloadContainsTimingsAndInitConfig()
    {
        $initOptions = [
            'sdkKey' => self::SDK_KEY,
            'accountId' => self::ACCOUNT_ID,
            'pollInterval' => 60,
            'isAliasingEnabled' => true,
            'proxy' => ['url' => 'https://proxy.example.com'],
            'storage' => new \stdClass(),
            'integrations' => function () {},
            'logger' => ['level' => 'DEBUG'],
        ];

        $payload = $this->networkUtil->getSDKUsageStatsEventPayload(
            EventEnum::USAGE_STATS,
            self::USAGE_STATS_ACCOUNT_ID,
            150,
            320,
            $initOptions
        );

        $data = $this->getEventData($payload);

        $this->assertSame(150, $data['settingsFetchTime']);
        $this->assertSame(320, $data['sdkInitTime']);
        $this->assertArrayNotHasKey('isSDKInitialized', $data);

        $initConfig = $data['initConfig'];
        $this->assertSame(self::SDK_KEY, $initConfig['sdkKey']);
        $this->assertSame(self::ACCOUNT_ID, $initConfig['accountId']);
        $this->assertSame(60, $initConfig['pollInterval']);
        $this->assertTrue($initConfig['isAliasingEnabled']);
        $this->assertSame('https://proxy.example.com', $initConfig['proxyUrl']);
        $this->assertTrue($initConfig['storage']);
        $this->assertTrue($initConfig['integrations']);
        $this->assertFalse($initConfig['networkClientInterface']);
        $this->assertFalse($initConfig['segmentEvaluator']);
        // Unset options must be omitted (match Java/.NET removeNullValues behavior)
        $this->assertArrayNotHasKey('gatewayService', $initConfig);
        $this->assertArrayNotHasKey('isUsageStatsDisabled', $initConfig);
        $this->assertArrayNotHasKey('_vwo_meta', $initConfig);
        $this->assertArrayNotHasKey('shouldWaitForTrackingCalls', $initConfig);
        $this->assertArrayNotHasKey('isDevelopmentMode', $initConfig);
        $this->assertArrayNotHasKey('hostProfile', $initConfig);
        $this->assertArrayNotHasKey('retryConfig', $initConfig);
        $this->assertArrayNotHasKey('batchEventData', $initConfig);
    }

    /** Usage stats initConfig should flatten retry and batch event config. */
    public function testUsageStatsInitConfigFlattensRetryAndBatchConfig()
    {
        $initOptions = [
            'sdkKey' => self::SDK_KEY,
            'accountId' => self::ACCOUNT_ID,
            'retryConfig' => [
                'shouldRetry' => true,
                'maxRetries' => 3,
                'initialDelay' => 100,
                'backoffMultiplier' => 2,
            ],
            'batchEvents' => [
                'eventsPerRequest' => 50,
                'requestTimeInterval' => 10,
                'flushCallback' => function () {},
            ],
        ];

        $payload = $this->networkUtil->getSDKUsageStatsEventPayload(
            EventEnum::USAGE_STATS,
            self::USAGE_STATS_ACCOUNT_ID,
            10,
            20,
            $initOptions
        );

        $initConfig = $this->getEventData($payload)['initConfig'];

        $this->assertTrue($initConfig['retryConfig']['shouldRetry']);
        $this->assertSame(3, $initConfig['retryConfig']['maxRetries']);
        $this->assertSame(100, $initConfig['retryConfig']['initialDelay']);
        $this->assertSame(2, $initConfig['retryConfig']['backoffMultiplier']);

        $this->assertSame(50, $initConfig['batchEventData']['eventsPerRequest']);
        $this->assertSame(10, $initConfig['batchEventData']['requestTimeInterval']);
        $this->assertTrue($initConfig['batchEventData']['flushCallback']);
    }

    /** Usage stats query params should include sdk key in env. */
    public function testUsageStatsQueryParamsIncludeSdkKeyInEnv()
    {
        $queryParams = $this->networkUtil->getEventsBaseProperties(
            EventEnum::USAGE_STATS,
            null,
            null,
            true,
            self::USAGE_STATS_ACCOUNT_ID
        );

        $this->assertSame(EventEnum::USAGE_STATS, $queryParams['en']);
        $this->assertSame((string) self::USAGE_STATS_ACCOUNT_ID, (string) $queryParams['a']);
        $this->assertSame(self::SDK_KEY, $queryParams['env']);
        $this->assertSame('FS', $queryParams['p']);
    }

    /** Usage stats payload should omit null timings and null initConfig. */
    public function testUsageStatsPayloadOmitsNullTimingsAndInitConfig()
    {
        $payload = $this->networkUtil->getSDKUsageStatsEventPayload(
            EventEnum::USAGE_STATS,
            self::USAGE_STATS_ACCOUNT_ID,
            null,
            null,
            null
        );

        $props = $this->getEventProps($payload);
        $this->assertArrayNotHasKey('data', $props);
    }

    /** initConfig should omit null nested fields (e.g. missing batch keys). */
    public function testInitConfigOmitsNullNestedBatchFields()
    {
        $initOptions = [
            'sdkKey' => self::SDK_KEY,
            'accountId' => self::ACCOUNT_ID,
            'batchEvents' => [
                'eventsPerRequest' => 25,
                // requestTimeInterval intentionally unset
                'flushCallback' => function () {},
            ],
        ];

        $payload = $this->networkUtil->getSDKUsageStatsEventPayload(
            EventEnum::USAGE_STATS,
            self::USAGE_STATS_ACCOUNT_ID,
            5,
            10,
            $initOptions
        );

        $batchConfig = $this->getEventData($payload)['initConfig']['batchEventData'];
        $this->assertSame(25, $batchConfig['eventsPerRequest']);
        $this->assertTrue($batchConfig['flushCallback']);
        $this->assertArrayNotHasKey('requestTimeInterval', $batchConfig);
    }

    /** Returns the event data object from a constructed payload. */
    private function getEventData(array $payload)
    {
        return $this->getEventProps($payload)['data'];
    }

    /** Returns the event props object from a constructed payload. */
    private function getEventProps(array $payload)
    {
        $this->assertArrayHasKey('d', $payload);
        $this->assertArrayHasKey('event', $payload['d']);
        $this->assertArrayHasKey('props', $payload['d']['event']);
        return $payload['d']['event']['props'];
    }
}
