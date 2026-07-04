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

namespace wingify\Packages\SegmentationEvaluator\Utils;

use wingify\Models\User\ContextModel;
use wingify\Services\ServiceContainer;
use wingify\Enums\ApiEnum;
use wingify\Utils\DataTypeUtil;

class WebTestingSegmentUtil {
    /**
     * Normalizes Web Testing campaign map keys and variation values to strings.
     * @param array|object $rawAssignments - The raw assignments map from the context.
     * @return array - The normalized assignments map with campaignId as key and variationId as value.
     */
    public static function normalizeWebTestingCampaignsMap($rawAssignments): array {
        // Turn the raw assignments map into a simple string map for regex matching.
        $campaignIdToVariationId = [];
        $iterableAssignments = is_object($rawAssignments) ? get_object_vars($rawAssignments) : $rawAssignments;

        foreach ($iterableAssignments as $campaignId => $assignedVariationId) {
            if (
                !is_null($assignedVariationId) &&
                strlen((string)$campaignId) > 0
                // Ignore empty keys; null/undefined variations mean nothing assigned for that id.
            ) {
                $campaignIdToVariationId[(string)$campaignId] = (string)$assignedVariationId;
            }
        }
        return $campaignIdToVariationId;
    }

    /**
     * Parses `context.platformVariables.webTestingCampaigns` (JSON string or plain object).
     */
    public static function parseWebTestingCampaignsFromContext(
        ContextModel $context,
        ServiceContainer $serviceContainer
    ): ?array {
        $platformVariables = $context->getPlatformVariables();
        $webTestingCampaignsInput = null;
        if (is_array($platformVariables) && isset($platformVariables['webTestingCampaigns'])) {
            $webTestingCampaignsInput = $platformVariables['webTestingCampaigns'];
        } elseif (is_object($platformVariables) && isset($platformVariables->webTestingCampaigns)) {
            $webTestingCampaignsInput = $platformVariables->webTestingCampaigns;
        }

        // No payload from the integration means empty assignments map.
        if (is_null($webTestingCampaignsInput)) {
            return null;
        }

        // SDK already forwarded a plain campaignId -> variationId object.
        if (is_array($webTestingCampaignsInput) || is_object($webTestingCampaignsInput)) {
            return self::normalizeWebTestingCampaignsMap($webTestingCampaignsInput);
        }

        // Some stacks pass JSON text (cookie, SSR prop, tag); parse it only if it's an object.
        if (DataTypeUtil::isString($webTestingCampaignsInput)) {
            $trimmedWebTestingCampaignsJson = trim($webTestingCampaignsInput);
            if ($trimmedWebTestingCampaignsJson === '') {
                // Empty JSON string is invalid.
                return null;
            }
            try {
                // extract all "key": tokens and check for duplicates before parsing swallows them
                preg_match_all('/"([^"\\\\]*)"\s*:/', $trimmedWebTestingCampaignsJson, $matches);
                if (!empty($matches[1])) {
                    $campaignIds = $matches[1];
                    $hasDuplicateCampaignId = count($campaignIds) !== count(array_unique($campaignIds));
                    if ($hasDuplicateCampaignId) {
                        $serviceContainer
                            ->getLoggerService()
                            ->error(
                                'INVALID_WEB_TESTING_CAMPAIGNS_DUPLICATE_KEY',
                                ['an' => ApiEnum::GET_FLAG, 'uuid' => $context->getUUID(), 'sId' => $context->getSessionId()]
                            );
                    }
                }
                // Parse the JSON string into an object.
                $parsedAssignments = json_decode($trimmedWebTestingCampaignsJson, true);
                if (json_last_error() === JSON_ERROR_NONE && (is_object(json_decode($trimmedWebTestingCampaignsJson)) || is_array($parsedAssignments))) {
                    return self::normalizeWebTestingCampaignsMap($parsedAssignments);
                }
                // Parsed fine but it's an array/string/etc. Invalid shape.
                $serviceContainer
                    ->getLoggerService()
                    ->error(
                        'INVALID_WEB_TESTING_CAMPAIGNS_JSON',
                        ['an' => ApiEnum::GET_FLAG, 'uuid' => $context->getUUID(), 'sId' => $context->getSessionId()]
                    );
            } catch (\Exception $e) {
                // Malformed JSON; treat like missing assignments.
                $serviceContainer
                    ->getLoggerService()
                    ->error(
                        'INVALID_WEB_TESTING_CAMPAIGNS_JSON',
                        ['an' => ApiEnum::GET_FLAG, 'uuid' => $context->getUUID(), 'sId' => $context->getSessionId()]
                    );
            }
            return null;
        }

        // Booleans/numbers/other odd types are invalid.
        if (!is_null($webTestingCampaignsInput)) {
            $kind = strtolower(gettype($webTestingCampaignsInput));
            $serviceContainer
                ->getLoggerService()
                ->error(
                    'INVALID_WEB_TESTING_CAMPAIGNS_TYPE',
                    ['kind' => $kind, 'an' => ApiEnum::GET_FLAG, 'uuid' => $context->getUUID(), 'sId' => $context->getSessionId()]
                );
        }
        return null;
    }

    /**
     * Evaluates campaignVariation operand encoding:
     * - "!C" — user is not in campaign C (no entry in map)
     * - "C_!V" — user is in campaign C and assigned variation is not V
     * - "C_V" — user is in campaign C with variation V
     * - "C" (digits only) — user is in campaign C (any variation)
     */
    public static function evaluateWebTestingCampaignVariation(
        string $campaignVariationOperand,
        ?array $assignedVariationsByCampaignId
    ): array {
        // Null means empty assignments map.
        $assignments = $assignedVariationsByCampaignId ?? [];

        // !123 — user should not be in campaign 123.
        if (preg_match('/^!(\d+)$/', $campaignVariationOperand, $match)) {
            $campaignId = $match[1];
            return ['result' => !array_key_exists($campaignId, $assignments), 'invalidFormat' => false];
        }

        // 123_!4 — in campaign 123 but not the variation 4.
        if (preg_match('/^(\d+)_!(\d+)$/', $campaignVariationOperand, $match)) {
            $campaignId = $match[1];
            $variationId = $match[2];
            if (!array_key_exists($campaignId, $assignments)) {
                return ['result' => false, 'invalidFormat' => false];
            }
            return ['result' => $assignments[$campaignId] !== $variationId, 'invalidFormat' => false];
        }

        // 123_4 — must be exactly that campaign and variation.
        if (preg_match('/^(\d+)_(\d+)$/', $campaignVariationOperand, $match)) {
            $campaignId = $match[1];
            $variationId = $match[2];
            if (!array_key_exists($campaignId, $assignments)) {
                return ['result' => false, 'invalidFormat' => false];
            }
            return ['result' => $assignments[$campaignId] === $variationId, 'invalidFormat' => false];
        }

        // 123 — in the campaign, any variation counts.
        if (preg_match('/^(\d+)$/', $campaignVariationOperand, $match)) {
            $campaignId = $match[1];
            return ['result' => array_key_exists($campaignId, $assignments), 'invalidFormat' => false];
        }

        // Invalid format.
        return ['result' => false, 'invalidFormat' => true];
    }
}
