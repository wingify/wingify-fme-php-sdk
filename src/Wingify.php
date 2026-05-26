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

use wingify\Utils\DataTypeUtil;
use wingify\Models\SettingsModel;
use Exception;
use wingify\Services\LoggerService;
use wingify\Utils\SdkInitAndUsageStatsUtil;
use wingify\Enums\LogMessagesEnum;
use wingify\Utils\LogMessageUtil;
use wingify\Enums\ApiEnum;
use wingify\Utils\UuidUtil;
use wingify\Constants\Constants;
use wingify\Utils\LogPrefixUtil;

class Wingify
{
    /** @var string Log prefix for static helpers (e.g. getUUID) before init completes */
    protected static $logPrefix = Constants::LOG_PREFIX_WINGIFY;

    /**
     * @param array $options SDK init options (used for host profile / logger prefix resolution)
     */
    protected static function applyStaticLogPrefix(array $options = [])
    {
        self::$logPrefix = LogPrefixUtil::resolveDefaultPrefix($options);
    }

    /**
     * @param array $options
     * @return WingifyBuilder
     */
    protected static function createDefaultBuilder($options)
    {
        return new WingifyBuilder($options);
    }

    /**
     * Creates and returns a new Wingify client with the provided options.
     * This method supports multiple instances by creating a new WingifyBuilder each time.
     *
     * @param array $options Configuration options for setting up the SDK.
     * @return array{instance: WingifyClient|null, vwoBuilder: WingifyBuilder}
     */
    private static function createInstance($options)
    {
        if (isset($options['wingifyBuilder'])) {
            $builder = $options['wingifyBuilder'];
        } elseif (isset($options['vwoBuilder'])) {
            $builder = $options['vwoBuilder'];
        } else {
            $builder = static::createDefaultBuilder($options);
        }

        $builder
            ->setLogger()
            ->setSettingsService()
            ->setStorage()
            ->setNetworkManager()
            ->setSegmentation()
            ->initBatching()
            ->initUsageStats();

        $logManager = $builder->getLogger();
        $loggerService = $builder->getLoggerService();

        if (isset($options['settings'])) {
            $settingsObject = json_decode($options['settings']);
            if ($builder->getSettingsService()->settingsSchemaValidator->isSettingsValid($settingsObject)) {
                $builder->getSettingsService()->isSettingsValidOnInit = true;
                $builder->getSettingsService()->settingsFetchTime = 0;
                if ($logManager) {
                    $logManager->info('SETTINGS_PASSED_IN_INIT_VALID');
                }
                $builder->setSettings($settingsObject);
                $settings = new SettingsModel($settingsObject);
            } else {
                $builder->getSettingsService()->isSettingsValidOnInit = false;
                $builder->getSettingsService()->settingsFetchTime = 0;
                if ($loggerService) {
                    $loggerService->error('INVALID_SETTINGS_SCHEMA', ['an' => ApiEnum::INIT]);
                }
                $settingsObject = json_decode('{}');
                $builder->setSettings($settingsObject);
                $settings = new SettingsModel($settingsObject);
            }
        } else {
            $settings = $builder->getSettings();
        }

        $instance = null;
        if ($settings) {
            $instance = $builder->build($settings);
        }

        return ['instance' => $instance, 'vwoBuilder' => $builder];
    }

    /**
     * Initializes a new Wingify SDK instance with the provided options.
     * Each call creates a new independent instance, supporting multiple SDK instances.
     *
     * @param array $options Configuration options for the SDK instance.
     * @return WingifyClient|null The initialized client instance.
     */
    public static function init($options = [])
    {
        self::applyStaticLogPrefix($options);

        $initStartTime = microtime(true) * 1000;
        $apiName = 'init';
        try {
            if (!DataTypeUtil::isObject($options)) {
                self::logErrorMessage(LogMessageUtil::buildMessage(LogMessagesEnum::getErrorMessages()['INVALID_OPTIONS']));
                throw new Exception('Options should be of type object.');
            }

            if (!isset($options['sdkKey']) || !is_string($options['sdkKey'])) {
                self::logErrorMessage(LogMessageUtil::buildMessage(LogMessagesEnum::getErrorMessages()['INVALID_SDK_KEY_IN_OPTIONS']));
                throw new Exception('Please provide the sdkKey in the options and should be of type string');
            }

            if (!isset($options['accountId'])) {
                self::logErrorMessage(LogMessageUtil::buildMessage(LogMessagesEnum::getErrorMessages()['INVALID_ACCOUNT_ID_IN_OPTIONS']));
                throw new Exception('Please provide account ID in the options and should be of type string|number');
            }

            if (isset($options['isAliasingEnabled']) && !isset($options['gatewayService']['url'])) {
                throw new Exception('Please provide the gatewayService URL in the options if aliasing is enabled');
            }

            $result = self::createInstance($options);
            $instance = $result['instance'];
            $builder = $result['vwoBuilder'];

            if (!$instance) {
                return null;
            }

            $initTime = (int)((microtime(true) * 1000) - $initStartTime);
            $wasInitializedEarlier = false;

            if (isset($builder->originalSettings) && isset($builder->originalSettings->sdkMetaInfo) && isset($builder->originalSettings->sdkMetaInfo->wasInitializedEarlier)) {
                $wasInitializedEarlier = $builder->originalSettings->sdkMetaInfo->wasInitializedEarlier;
            } else {
                $wasInitializedEarlier = false;
            }

            if (!isset($options['isDebuggerUsed']) || !($options['isDebuggerUsed'])) {
                if ($builder->getSettingsService()->isSettingsValidOnInit && !$wasInitializedEarlier) {
                    SdkInitAndUsageStatsUtil::sendSdkInitEvent($builder->getSettingsService()->settingsFetchTime, $initTime, $builder->serviceContainer);
                }
            }

            if (isset($builder->originalSettings->usageStatsAccountId) && $builder->originalSettings->usageStatsAccountId !== null) {
                $usageStatsAccountId = $builder->originalSettings->usageStatsAccountId;
            } else {
                $usageStatsAccountId = null;
            }
            if ($usageStatsAccountId) {
                SdkInitAndUsageStatsUtil::sendSDKUsageStatsEvent($usageStatsAccountId, $builder->serviceContainer);
            }

            return $instance;
        } catch (\Throwable $err) {
            $msg = LogMessageUtil::buildMessage(LogMessagesEnum::getErrorMessages()['EXECUTION_FAILED'], [
                'apiName' => $apiName,
                'err' => $err,
                'an' => ApiEnum::INIT,
            ]);

            self::logErrorMessage($msg);
            return null;
        }
    }

    /**
     * Generate a deterministic UUID for a given user and account combination.
     *
     * @param string $userId
     * @param string $accountId
     * @return string|null UUID without dashes in uppercase, or null on invalid input
     */
    public static function getUUID($userId, $accountId)
    {
        $apiName = 'getUUID';
        $prefix = self::$logPrefix;

        try {
            $logMessage = sprintf('[DEBUG]: %s %s API Called: %s', $prefix, (new \DateTime())->format(DATE_ISO8601), $apiName);
            file_put_contents("php://stdout", $logMessage . PHP_EOL);

            if (!is_string($userId) || $userId === '') {
                $logMessage = sprintf('[ERROR]: %s %s userId passed to %s API is not of valid type.', $prefix, (new \DateTime())->format(DATE_ISO8601), $apiName);
                file_put_contents("php://stdout", $logMessage . PHP_EOL);
                return null;
            }

            if (!is_string($accountId) || $accountId === '') {
                $logMessage = sprintf('[ERROR]: %s %s accountId passed to %s API is not of valid type.', $prefix, (new \DateTime())->format(DATE_ISO8601), $apiName);
                file_put_contents("php://stdout", $logMessage . PHP_EOL);
                return null;
            }

            return UuidUtil::getUUID($userId, $accountId);
        } catch (\Throwable $error) {
            $msg = sprintf('API - %s failed to execute. Trace: %s. ', $apiName, $error->getMessage());
            $logMessage = sprintf('[ERROR]: %s %s %s', $prefix, (new \DateTime())->format(DATE_ISO8601), $msg);
            file_put_contents("php://stdout", $logMessage . PHP_EOL);
            return null;
        }
    }

    /**
     * Logs error messages to stdout with the resolved log prefix and timestamp.
     *
     * @param string $message The error message to log.
     */
    private static function logErrorMessage($message)
    {
        $errorLog = sprintf('[ERROR]: %s %s %s', self::$logPrefix, (new \DateTime())->format(DATE_ISO8601), $message);
        file_put_contents("php://stdout", $errorLog . PHP_EOL);
    }
}
