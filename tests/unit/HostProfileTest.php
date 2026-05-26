<?php

/**
 * Copyright 2024-2025 Wingify Software Pvt. Ltd.
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
use wingify\Packages\Logger\Core\LogManager;
use wingify\Services\LoggerService;
use wingify\Services\SettingsService;
use wingify\Utils\LogPrefixUtil;
use vwo\VWOBuilder;

class HostProfileTest extends TestCase
{
    private function createSettingsService(array $options)
    {
        $logManager = new LogManager([]);
        $loggerService = new LoggerService($logManager);

        return new SettingsService($options, $logManager, $loggerService);
    }

    public function testWingifyProfileUsesEdgeAndCollectHosts()
    {
        $service = $this->createSettingsService([
            'sdkKey' => 'test-key',
            'accountId' => '12345',
            'hostProfile' => Constants::HOST_PROFILE_WINGIFY,
        ]);

        $this->assertEquals(Constants::EDGE_HOST, $service->getSettingsHostname());
        $this->assertEquals(Constants::COLLECT_HOST, $service->getEventsHostname());
        $this->assertFalse($service->usesCustomerHostOverride());
    }

    public function testVwoProfileUsesLegacyHostForAllTraffic()
    {
        $service = $this->createSettingsService([
            'sdkKey' => 'test-key',
            'accountId' => '12345',
            'hostProfile' => Constants::HOST_PROFILE_VWO,
        ]);

        $this->assertEquals(Constants::LEGACY_HOST, $service->getSettingsHostname());
        $this->assertEquals(Constants::LEGACY_HOST, $service->getEventsHostname());
    }

    public function testGatewayOverridesPlatformHosts()
    {
        $service = $this->createSettingsService([
            'sdkKey' => 'test-key',
            'accountId' => '12345',
            'hostProfile' => Constants::HOST_PROFILE_WINGIFY,
            'gatewayService' => [
                'url' => 'https://gateway.example.com:8443',
            ],
        ]);

        $this->assertEquals('gateway.example.com', $service->getSettingsHostname());
        $this->assertEquals('gateway.example.com', $service->getEventsHostname());
        $this->assertTrue($service->usesCustomerHostOverride());
    }

    public function testProxyOverridesPlatformHosts()
    {
        $service = $this->createSettingsService([
            'sdkKey' => 'test-key',
            'accountId' => '12345',
            'hostProfile' => Constants::HOST_PROFILE_WINGIFY,
            'proxy' => [
                'url' => 'https://proxy.customer.com',
            ],
        ]);

        $this->assertEquals('proxy.customer.com', $service->getSettingsHostname());
        $this->assertEquals('proxy.customer.com', $service->getEventsHostname());
        $this->assertTrue($service->isProxyUrlProvided);
    }

    public function testVwoShimBuilderDefaultsToVwoHostProfile()
    {
        $builder = new VWOBuilder([
            'sdkKey' => 'test-key',
            'accountId' => '12345',
        ]);
        $builder->setLogger()->setSettingsService();

        $this->assertEquals(Constants::HOST_PROFILE_VWO, $builder->getSettingsService()->hostProfile);
        $this->assertEquals(Constants::LEGACY_HOST, $builder->getSettingsService()->getEventsHostname());
    }

    public function testWingifyProfileUsesWingifyLogPrefixByDefault()
    {
        $config = LogPrefixUtil::buildLoggerConfig([
            'sdkKey' => 'test-key',
            'accountId' => '12345',
            'hostProfile' => Constants::HOST_PROFILE_WINGIFY,
        ]);

        $this->assertEquals(Constants::LOG_PREFIX_WINGIFY, $config['prefix']);
        $this->assertEquals(Constants::LOGGER_NAME_WINGIFY, $config['name']);
    }

    public function testVwoProfileUsesVwoLogPrefixByDefault()
    {
        $config = LogPrefixUtil::buildLoggerConfig([
            'sdkKey' => 'test-key',
            'accountId' => '12345',
            'hostProfile' => Constants::HOST_PROFILE_VWO,
        ]);

        $this->assertEquals(Constants::LOG_PREFIX_VWO, $config['prefix']);
        $this->assertEquals(Constants::LOGGER_NAME_VWO, $config['name']);
    }

    public function testCustomerLoggerPrefixOverridesProfileDefault()
    {
        $config = LogPrefixUtil::buildLoggerConfig([
            'sdkKey' => 'test-key',
            'accountId' => '12345',
            'hostProfile' => Constants::HOST_PROFILE_VWO,
            'logger' => ['prefix' => 'MyApp-SDK'],
        ]);

        $this->assertEquals('MyApp-SDK', $config['prefix']);
    }
}
