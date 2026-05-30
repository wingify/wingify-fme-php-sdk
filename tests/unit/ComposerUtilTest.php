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
use wingify\Utils\ComposerUtil;

class ComposerUtilTest extends TestCase
{
    public function testSdkIdentityUsesWingifyPackageWhenOnlyWingifyIsInstalled()
    {
        $this->assertEquals(ComposerUtil::PACKAGE_WINGIFY, ComposerUtil::getSdkPackageName());
        $this->assertEquals('wingify-fme-php-sdk', ComposerUtil::getSdkName());
        $this->assertNotEmpty(ComposerUtil::getSdkVersion());
    }
}
