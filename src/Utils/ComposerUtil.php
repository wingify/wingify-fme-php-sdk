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

use Composer\InstalledVersions;
use Exception;
use wingify\Constants\Constants;

class ComposerUtil
{
    const PACKAGE_WINGIFY = 'wingify/wingify-fme-php-sdk';
    const PACKAGE_VWO = 'vwo/vwo-fme-php-sdk';

    /**
     * Fallback paths when InstalledVersions is unavailable (standard vendor/ layout from src/Utils).
     */
    const VWO_VENDOR_COMPOSER_JSON_RELATIVE = '/../../../vwo/vwo-fme-php-sdk/composer.json';
    const WINGIFY_COMPOSER_JSON_RELATIVE = '/../../composer.json';

    private static $composerDataByPackage = [];

    /**
     * Composer package name used for SDK identity in network payloads.
     * Prefers vwo/vwo-fme-php-sdk when the legacy facade package is installed.
     */
    public static function getSdkPackageName()
    {
        if (class_exists(InstalledVersions::class)) {
            if (InstalledVersions::isInstalled(self::PACKAGE_VWO)) {
                return self::PACKAGE_VWO;
            }
            if (InstalledVersions::isInstalled(self::PACKAGE_WINGIFY)) {
                return self::PACKAGE_WINGIFY;
            }
        }

        if (file_exists(__DIR__ . self::VWO_VENDOR_COMPOSER_JSON_RELATIVE)) {
            return self::PACKAGE_VWO;
        }

        return self::PACKAGE_WINGIFY;
    }

    /**
     * Short SDK name sent in network calls (e.g. vwo-fme-php-sdk, wingify-fme-php-sdk).
     */
    public static function getSdkName()
    {
        $composerData = self::loadComposerDataForPackage(self::getSdkPackageName());
        if (!empty($composerData['name'])) {
            $parts = explode('/', $composerData['name']);
            return end($parts);
        }

        return Constants::SDK_NAME;
    }

    /**
     * SDK version from the installed facade package's composer.json.
     */
    public static function getSdkVersion()
    {
        $packageName = self::getSdkPackageName();

        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($packageName)) {
            $version = InstalledVersions::getPrettyVersion($packageName);
            if ($version !== null && $version !== '') {
                return $version;
            }
        }

        $composerData = self::loadComposerDataForPackage($packageName);
        if (!empty($composerData['version'])) {
            return $composerData['version'];
        }

        return Constants::SDK_VERSION;
    }

    private static function loadComposerDataForPackage($packageName)
    {
        if (isset(self::$composerDataByPackage[$packageName])) {
            return self::$composerDataByPackage[$packageName];
        }

        $composerJsonPath = self::resolveComposerJsonPath($packageName);
        if ($composerJsonPath === null || !file_exists($composerJsonPath)) {
            self::$composerDataByPackage[$packageName] = [];
            return self::$composerDataByPackage[$packageName];
        }

        $composerJsonContent = file_get_contents($composerJsonPath);
        $composerData = json_decode($composerJsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Error decoding composer.json: ' . json_last_error_msg());
        }

        self::$composerDataByPackage[$packageName] = is_array($composerData) ? $composerData : [];
        return self::$composerDataByPackage[$packageName];
    }

    private static function resolveComposerJsonPath($packageName)
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($packageName)) {
            $installPath = InstalledVersions::getInstallPath($packageName);
            if ($installPath !== null) {
                return rtrim($installPath, '/') . '/composer.json';
            }
        }

        if ($packageName === self::PACKAGE_VWO) {
            $vwoComposerPath = __DIR__ . self::VWO_VENDOR_COMPOSER_JSON_RELATIVE;
            if (file_exists($vwoComposerPath)) {
                return $vwoComposerPath;
            }
        }

        return __DIR__ . self::WINGIFY_COMPOSER_JSON_RELATIVE;
    }
}
