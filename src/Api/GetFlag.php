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

namespace wingify\Api;

use wingify\Decorators\StorageDecorator;
use wingify\Models\CampaignModel;
use wingify\Models\FeatureModel;
use wingify\Models\VariationModel;
use wingify\Models\User\ContextModel;
use wingify\Services\StorageService;
use wingify\Services\HooksService;
use wingify\Enums\ApiEnum;
use wingify\Enums\CampaignTypeEnum;
use wingify\Packages\Logger\Core\LogManager;
use wingify\Packages\SegmentationEvaluator\Core\SegmentationManager;
use wingify\Utils\CampaignUtil;
use wingify\Utils\DataTypeUtil;
use wingify\Utils\DecisionUtil;
use wingify\Utils\FunctionUtil;
use wingify\Utils\ImpressionUtil;
use wingify\Utils\GetFlagResultUtil;
use wingify\Utils\RuleEvaluationUtil;
use wingify\Services\ServiceContainer;
use wingify\Utils\NetworkUtil;
use wingify\Enums\EventEnum;
use wingify\Services\SettingsService;
use wingify\Utils\DebuggerServiceUtil;
use wingify\Enums\DebuggerCategoryEnum;
use wingify\Services\LoggerService;
use wingify\Packages\Logger\Enums\LogLevelEnum;
use wingify\Constants\Constants;
use wingify\Utils\HoldoutUtil;

class GetFlag
{
    public function get(
        string $featureKey,
        ContextModel $context,
        ServiceContainer $serviceContainer,
        bool $isDebuggerUsed = false
    ) {
        $isVariationShownFired = false;
        $ruleEvaluationUtil = new RuleEvaluationUtil();
        $isEnabled = false;
        $rolloutVariationToReturn = null;
        $experimentVariationToReturn = null;
        $shouldCheckForExperimentsRules = false;
        $batchPayload = [];
        $notInHoldoutIds = [];

        $passedRulesInformation = [];
        $evaluatedFeatureMap = [];
        $storageService = new StorageService();
        $ruleStatus = [];
        $batchPayload = [];

        $hooksService = $serviceContainer->getHooksService();
        $logManager = $serviceContainer->getLogManager();
        $loggerService = $serviceContainer->getLoggerService();

        // Get feature object from feature key
        $feature = FunctionUtil::getFeatureFromKey($serviceContainer->getSettings(), $featureKey);
        $decision = [
            'featureName' => $feature ? $feature->getName() : null,
            'featureId' => $feature ? $feature->getId() : null,
            'featureKey' => $feature ? $feature->getKey() : null,
            'userId' => $context ? $context->getId() : null,
            'api' => ApiEnum::GET_FLAG,
            'holdoutIDs' => [],
            'isPartOfHoldout' => false,
            'isHoldoutPresent' => false,
            'isUserPartOfCampaign' => false,
        ];

        // create debug event props
        $debugEventProps = [
            'an' => ApiEnum::GET_FLAG,
            'uuid' => $context ? $context->getUUID() : null,
            'fk' => $feature ? $feature->getKey() : null,
            'sId' => $context ? $context->getSessionId() : null,
        ];

        // Retrieve stored data
        $storedData = (new StorageDecorator())->getFeatureFromStorage(
            $featureKey,
            $context,
            $storageService,
            $serviceContainer
        );
    
        // check if stored data has isInHoldoutId or holdoutGroupId
        $storedIsInHoldoutId = null;
        if (is_array($storedData)) {
            $storedIsInHoldoutId = $storedData['isInHoldoutId'] ?? [];
        }
        $storedNotInHoldoutId = $storedData['notInHoldoutId'] ?? [];
        // if storedData has isInHoldoutId, then check if the settings stil contain atleast 1 holdoutGroup that is present in the storedData
        if ($storedIsInHoldoutId && (is_array($storedIsInHoldoutId) ? count($storedIsInHoldoutId) > 0 : true)) {
            // get all appicable holdouts for the feature
            $applicableHoldouts = HoldoutUtil::getApplicableHoldouts($serviceContainer->getSettings(), $feature->getId());
            if (count($applicableHoldouts) > 0) {
                foreach ($applicableHoldouts as $holdout) {
                    // if the holdout id is present in the storedData, then return the disabled flag
                    if (in_array($holdout->getId(), $storedIsInHoldoutId, true)) {
                    $loggerService->info('STORED_HOLDOUT_DECISION_FOUND', [
                        'userId' => $context->getId(),
                        'holdoutId' => is_array($storedIsInHoldoutId) ? implode(',', $storedIsInHoldoutId) : (string)$storedIsInHoldoutId,
                        'featureKey' => $feature->getKey(), 
                    ]);
                    // evaluate the new holdouts in settings file and send the impression for them
                    $holdoutResult = HoldoutUtil::getMatchedHoldouts(
                        $serviceContainer,
                        $feature,
                        $context,
                        $storedData
                    );
                    $matchedHoldouts = $holdoutResult['matchedHoldouts'];
                    $notMatchedHoldouts = $holdoutResult['notMatchedHoldouts'];
                    $holdoutPayloads = $holdoutResult['holdoutPayloads'];
        
                    // case: evaluate the new holdouts in settings file and send the impression for them
                    // set isVariationShownFired to true if any holdout payloads are found
                    if (count($holdoutPayloads) > 0) {
                        $isVariationShownFired = true;
                    }
                    
                    // updatedHoldoutIds is the array of holdout ids for which user became part of the holdouts
                    $updatedHoldoutIds = array_merge(
                        (array) $storedIsInHoldoutId,
                        array_map(function ($holdout) {
                            return $holdout->getId();
                        }, $matchedHoldouts)
                    );
                    $updatedNotInHoldoutIds = array_merge(
                        (array) $storedNotInHoldoutId,
                        array_map(function ($holdout) {
                            return $holdout->getId();
                        }, $notMatchedHoldouts)
                    );
                    
                    // store the updated holdout ids in storage and push the updated not in holdout ids to the notInHoldoutIds array
                    (new StorageDecorator())->setDataInStorage(
                        [
                            'featureKey' => $feature->getKey(),
                            'context' => $context,
                            'isInHoldoutId' => $updatedHoldoutIds,
                            'notInHoldoutId' => $updatedNotInHoldoutIds,
                        ],
                        $storageService,
                        $serviceContainer
                    );

                    // send the impression for the new holdouts
                    if(!$isDebuggerUsed) {
                        if(($serviceContainer->getSettingsService()->isGatewayServiceProvided || $serviceContainer->getSettingsService()->isProxyUrlProvided)) {
                            foreach($holdoutPayloads as $payload) {
                                ImpressionUtil::SendImpressionForVariationShown($serviceContainer, $payload, $context, $featureKey);
                            }
                        } else {
                            ImpressionUtil::SendImpressionForVariationShownInBatch($holdoutPayloads, $serviceContainer);
                        }
                    }

                    // case: user found in storage only for a holdout, no rules evaluated
                    // send usage tracking call if no primary variationShown event was dispatched
                    if ($serviceContainer->getSettings()->isTrackingUsageEnabled() && !$isVariationShownFired) {
                        ImpressionUtil::createAndSendImpressionForUsageTracking($serviceContainer->getSettings(), $featureKey, $context, $serviceContainer, $batchPayload);
                    }
                    if(!$serviceContainer->getSettingsService()->isGatewayServiceProvided && !$serviceContainer->getSettingsService()->isProxyUrlProvided && count($batchPayload) > 0) {
                        ImpressionUtil::SendImpressionForVariationShownInBatch($batchPayload, $serviceContainer);
                    }

                    return new GetFlagResultUtil(false, [], $ruleStatus, $context->getSessionId(), $context->getUUID());
                    }
                }
            }
        }
        
    // Check if stored data has featureId and if feature still exists in settings
    if (isset($storedData['featureId']) && FunctionUtil::isFeatureIdPresentInSettings($serviceContainer->getSettings(), $storedData['featureId'])) {
        if (isset($storedData['experimentVariationId'])) {
            if (isset($storedData['experimentKey'])) {
                $variation = CampaignUtil::getVariationFromCampaignKey(
                    $serviceContainer->getSettings(),
                    $storedData['experimentKey'],
                    $storedData['experimentVariationId']
                );
                if ($variation) {
                    $logManager->info(sprintf(
                        "Variation %s found in storage for the user %s for the experiment: %s",
                        $variation->getKey(),
                        $context->getId(),
                        $storedData['experimentKey']
                    ));
                    $decision['isUserPartOfCampaign'] = true;
                    // network calls for holdouts that are newly added in settings and are not present in storage
                    $holdoutCatchupResult = HoldoutUtil::sendNetworkCallsForNotInHoldouts($serviceContainer, $feature, $context, $decision, $storedData, $storageService);
                    $updatedNotInHoldoutIds = $holdoutCatchupResult['updatedNotInHoldoutIds'];
                    
                    // case: if updatedNotInHoldoutIds count is greater than storedNotInHoldoutId count, then it means that there are some new holdouts that are added in settings and are not present in storage, so set isVariationShownFired to true
                    if ($holdoutCatchupResult['isNetworkCallSent']) {
                        $isVariationShownFired = true;
                    }

                    // case: stored data found for the user but no holdout impression was sent
                    // send usage tracking for cached experiment decision if usage tracking is enabled
                    if ($serviceContainer->getSettings()->isTrackingUsageEnabled() && !$isVariationShownFired) {
                        ImpressionUtil::createAndSendImpressionForUsageTracking($serviceContainer->getSettings(), $featureKey, $context, $serviceContainer, $batchPayload);
                    }
                    
                    if(!$serviceContainer->getSettingsService()->isGatewayServiceProvided && !$serviceContainer->getSettingsService()->isProxyUrlProvided && count($batchPayload) > 0) {
                        ImpressionUtil::SendImpressionForVariationShownInBatch($batchPayload, $serviceContainer);
                    }
                    return new GetFlagResultUtil(true, $variation->getVariables(), $ruleStatus, $context->getSessionId(), $context->getUUID());
                }
            }
        } elseif (isset($storedData['rolloutKey']) && isset($storedData['rolloutId'])) {
            $variation = CampaignUtil::getVariationFromCampaignKey(
                $serviceContainer->getSettings(),
                $storedData['rolloutKey'],
                $storedData['rolloutVariationId']
            );

            if ($variation) {
                $logManager->info(sprintf(
                    "Variation %s found in storage for the user %s for the rollout experiment: %s",
                    $variation->getKey(),
                    $context->getId(),
                    $storedData['rolloutKey']
                ));
                $logManager->debug(sprintf(
                    "Rollout rule got passed for user %s. Hence, evaluating experiments",
                    $context->getId()
                ));

                // network calls for holdouts that are newly added in settings and are not present in storage
                $holdoutCatchupResult = HoldoutUtil::sendNetworkCallsForNotInHoldouts(
                        $serviceContainer,
                        $feature,
                        $context,
                        $decision,
                        $storedData,
                        $storageService
                    );
                $updatedNotInHoldoutIds = $holdoutCatchupResult['updatedNotInHoldoutIds'];
                
                // case: if updatedNotInHoldoutIds count is greater than storedNotInHoldoutId count, then it means that there are some new holdouts that are added in settings and are not present in storage, so set isVariationShownFired to true
                if ($holdoutCatchupResult['isNetworkCallSent']) {
                    $isVariationShownFired = true;
                }
                
                // push the updated not in holdout ids to the notInHoldoutIds array
                $notInHoldoutIds = array_merge($notInHoldoutIds, (array) $updatedNotInHoldoutIds);

                $isEnabled = true;
                $shouldCheckForExperimentsRules = true;
                $rolloutVariationToReturn = $variation;
                $evaluatedFeatureMap[$featureKey] = [
                    'rolloutId' => $storedData['rolloutId'],
                    'rolloutKey' => $storedData['rolloutKey'],
                    'rolloutVariationId' => $storedData['rolloutVariationId']
                ];
                $decision['isUserPartOfCampaign'] = true;
                $passedRulesInformation = array_merge($passedRulesInformation, $evaluatedFeatureMap[$featureKey]);
            }
        }
    }

        if (!DataTypeUtil::isObject($feature)) {
            $loggerService->error('FEATURE_NOT_FOUND', 
            array_merge([
                'featureKey' => $featureKey,
            ], $debugEventProps));

            // case: feature not found
            // If usage tracking is enabled, send usage tracking impression
            if ($serviceContainer->getSettings()->isTrackingUsageEnabled() && !$isVariationShownFired) {
                ImpressionUtil::createAndSendImpressionForUsageTracking($serviceContainer->getSettings(), $featureKey, $context, $serviceContainer, $batchPayload);
            }
            if(!$serviceContainer->getSettingsService()->isGatewayServiceProvided && !$serviceContainer->getSettingsService()->isProxyUrlProvided && count($batchPayload) > 0) {
                ImpressionUtil::SendImpressionForVariationShownInBatch($batchPayload, $serviceContainer);
            }

            return new GetFlagResultUtil(false, [], $ruleStatus, $context->getSessionId(), $context->getUUID());
        }

        // Set session ID if not present
        if ($context->getSessionId() === null) {
            $context->setSessionId(FunctionUtil::getCurrentUnixTimestamp());
        }

        $segmentationManager = $serviceContainer->getSegmentationManager();
        $segmentationManager->setContextualData($serviceContainer, $feature, $context);

        if(!$isEnabled) {
            // Holdout group exclusion: if user falls into any holdout group for this feature, return disabled
            $holdoutResult = HoldoutUtil::getMatchedHoldouts(
                $serviceContainer,
                $feature,
                $context,
                $storedData
            );
            $matchedHoldouts = $holdoutResult['matchedHoldouts'];
            $notMatchedHoldouts = $holdoutResult['notMatchedHoldouts'];
            $holdoutPayloads = $holdoutResult['holdoutPayloads'];

            // case: User is in holdout group
            // set isVariationShownFired to true if any holdout payloads are found
            if (count($holdoutPayloads) > 0) {
                $isVariationShownFired = true;
            }


            if ($matchedHoldouts !== null && count($matchedHoldouts) > 0) {
                // get the qualified holdout names
                $qualifiedHoldoutNames = implode(',', array_map(function ($holdout) {
                    return $holdout->getName();
                }, $matchedHoldouts));

                $decision['holdoutIDs'] = array_map(function ($holdout) {
                    return $holdout->getId();
                }, $matchedHoldouts);


                $loggerService->info('USER_IN_HOLDOUT_GROUP', [
                    'userId' => $context->getId(),
                    'holdoutGroupName' => $qualifiedHoldoutNames,
                    'featureKey' => $feature->getKey(),
                ]);


                // Store holdout decision in storage
                (new StorageDecorator())->setDataInStorage(
                    [
                    'featureKey' => $featureKey,
                    'context' => $context,
                    'isInHoldoutId' => array_map(function ($holdout) {
                        return $holdout->getId();
                    }, $matchedHoldouts),
                    'notInHoldoutId' => array_map(function ($holdout) {
                        return $holdout->getId();
                        }, $notMatchedHoldouts),
                    ],
                    $storageService,
                    $serviceContainer
                );
                $decision['isEnabled'] = false;

                $hooksService->set($decision);
                $hooksService->execute($hooksService->get());

                // send impression for variation shown for holdouts
                if(!$isDebuggerUsed) {
                    if(($serviceContainer->getSettingsService()->isGatewayServiceProvided || $serviceContainer->getSettingsService()->isProxyUrlProvided)) {
                        foreach($holdoutPayloads as $payload) {
                            ImpressionUtil::SendImpressionForVariationShown($serviceContainer, $payload, $context, $featureKey);
                        }
                    } else {
                        ImpressionUtil::SendImpressionForVariationShownInBatch($holdoutPayloads, $serviceContainer);
                    }
                }
                return new GetFlagResultUtil(false, [], $ruleStatus, $context->getSessionId(), $context->getUUID());
            } else {
                $loggerService->info('USER_NOT_EXCLUDED_DUE_TO_HOLDOUT',
                    [
                        'featureKey' => $featureKey,
                        'userId' => $context->getId(),
                    ]
                );

                // send impression for variation shown for holdouts for which user is not in holdout group and are present in settings
                if(!$isDebuggerUsed) {
                    if(($serviceContainer->getSettingsService()->isGatewayServiceProvided || $serviceContainer->getSettingsService()->isProxyUrlProvided)) {
                        foreach($holdoutPayloads as $payload) {
                            ImpressionUtil::SendImpressionForVariationShown($serviceContainer, $payload, $context, $featureKey);
                        }
                    } else {
                        // add payloads to batch payload
                        foreach($holdoutPayloads as $payload) {
                            $batchPayload[] = $payload;
                        }
                    }
                }
            }
        }

        // Evaluate Rollout Rules
        $rollOutRules = FunctionUtil::getSpecificRulesBasedOnType($feature, CampaignTypeEnum::ROLLOUT);
        if (count($rollOutRules) > 0 && !$isEnabled) {
            $megGroupWinnerCampaigns = [];
            foreach ($rollOutRules as $rule) {
                $evaluateRuleResult = $ruleEvaluationUtil->evaluateRule(
                    $serviceContainer,
                    $feature,
                    $rule,
                    $context,
                    $evaluatedFeatureMap,
                    $megGroupWinnerCampaigns,
                    $storageService,
                    $decision,
                    $isDebuggerUsed
                );

                if ($evaluateRuleResult['preSegmentationResult']) {
                    $payload = $evaluateRuleResult['payload'];

                    if(!$isDebuggerUsed) {
                        if(($serviceContainer->getSettingsService()->isGatewayServiceProvided || $serviceContainer->getSettingsService()->isProxyUrlProvided) && $payload !== null) {
                            ImpressionUtil::SendImpressionForVariationShown($serviceContainer, $payload, $context, $featureKey);
                        } else {
                            if($payload !== null) {
                                $batchPayload[] = $payload;
                            }
                        }
                    }

                    $evaluatedFeatureMap[$featureKey] = [
                        'rolloutId' => $rule->getId(),
                        'rolloutKey' => $rule->getKey(),
                        'rolloutVariationId' => $rule->getVariations()[0]->getId()
                    ];
                    $ruleStatus[$rule->getRuleKey()] = "Passed";
                    break;
                } else {
                    $ruleStatus[$rule->getRuleKey()] = "Failed";
                }
            }

            if (isset($evaluatedFeatureMap[$featureKey])) {
                $passedRolloutCampaign = new CampaignModel();
                $passedRolloutCampaign->modelFromDictionary($rule);
                $variation = DecisionUtil::evaluateTrafficAndGetVariation($serviceContainer, $passedRolloutCampaign, $context);

                if (DataTypeUtil::isObject($variation) && !empty($variation)) {
                    $isEnabled = true;
                    $decision['isUserPartOfCampaign'] = true;
                    $shouldCheckForExperimentsRules = true;
                    $rolloutVariationToReturn = $variation;

                    $this->updateIntegrationsDecisionObject($passedRolloutCampaign, $variation, $passedRulesInformation, $decision);
                    
                    if(!$isDebuggerUsed) {
                        //push this payload to the batch payload
                        $networkUtil = new NetworkUtil($serviceContainer);
                        $payload = $networkUtil->getTrackUserPayloadData(
                            $serviceContainer->getSettings(),
                            EventEnum::VARIATION_SHOWN,
                            $passedRolloutCampaign->getId(),
                            $variation->getId(),
                            $context
                        );

                        if(($serviceContainer->getSettingsService()->isGatewayServiceProvided || $serviceContainer->getSettingsService()->isProxyUrlProvided) && $payload !== null) {
                            ImpressionUtil::SendImpressionForVariationShown($serviceContainer, $payload, $context, $featureKey);
                        } else {
                            //push this payload to the batch payload
                            if($payload !== null) {
                                $batchPayload[] = $payload;
                            }
                        }
                    }

                    // case: rollout rule passed traffic evaluation
                    // set isVariationShownFired to true ONLY after traffic confirms a valid variation
                    $isVariationShownFired = true;
                }
            }
        } else if (count($rollOutRules) === 0) {
            $logManager->debug("No Rollout rules present for the feature. Hence, checking experiment rules");
            $shouldCheckForExperimentsRules = true;
        }

        // Evaluate Experiment Rules
        if ($shouldCheckForExperimentsRules) {
            $experimentRules = FunctionUtil::getAllExperimentRules($feature);
            $experimentRulesToEvaluate = [];

            $megGroupWinnerCampaigns = [];
            foreach ($experimentRules as $rule) {
                $evaluateRuleResult = $ruleEvaluationUtil->evaluateRule(
                    $serviceContainer,
                    $feature,
                    $rule,
                    $context,
                    $evaluatedFeatureMap,
                    $megGroupWinnerCampaigns,
                    $storageService,
                    $decision,
                    $isDebuggerUsed
                );

                if ($evaluateRuleResult['preSegmentationResult']) {
                    if ($evaluateRuleResult['whitelistedObject'] === null) {
                        $experimentRulesToEvaluate[] = $rule;
                    } else {
                        $isEnabled = true;
                        $decision['isUserPartOfCampaign'] = true;
                        $payload = $evaluateRuleResult['payload'];
                        
                        // case: User passed whitelisted experiment rule
                        // set isVariationShownFired to true
                        $isVariationShownFired = true;
                        if(($serviceContainer->getSettingsService()->isGatewayServiceProvided || $serviceContainer->getSettingsService()->isProxyUrlProvided) && $payload !== null) {
                            ImpressionUtil::SendImpressionForVariationShown($serviceContainer, $payload, $context, $featureKey);
                        } else {
                            if($payload !== null) {
                                $batchPayload[] = $payload;
                            }
                        }
                        $experimentVariationToReturn = $evaluateRuleResult['whitelistedObject']['variation'];

                        $passedRulesInformation = array_merge($passedRulesInformation, [
                            'experimentId' => $rule->getId(),
                            'experimentKey' => $rule->getKey(),
                            'experimentVariationId' => $evaluateRuleResult['whitelistedObject']['variationId'],
                        ]);
                    }
                    $ruleStatus[$rule->getRuleKey()] = "Passed";
                    break;
                } else {
                    $ruleStatus[$rule->getRuleKey()] = "Failed";
                }
            }

            if (isset($experimentRulesToEvaluate[0])) {
                $campaign = new CampaignModel();
                $campaign->modelFromDictionary($experimentRulesToEvaluate[0]);
                $variation = DecisionUtil::evaluateTrafficAndGetVariation($serviceContainer, $campaign, $context);

                if (DataTypeUtil::isObject($variation) && !empty($variation)) {
                    $isEnabled = true;
                    $decision['isUserPartOfCampaign'] = true;
                    $experimentVariationToReturn = $variation;

                    $this->updateIntegrationsDecisionObject($campaign, $variation, $passedRulesInformation, $decision);
                    
                    // case: User passed experiment rule
                    // set isVariationShownFired to true
                    $isVariationShownFired = true;

                    if(!$isDebuggerUsed) {
                         // Construct payload data for tracking the user
                         $networkUtil = new NetworkUtil($serviceContainer);
                         $payload = $networkUtil->getTrackUserPayloadData(
                             $serviceContainer->getSettings(),
                             EventEnum::VARIATION_SHOWN,
                             $campaign->getId(),
                             $variation->getId(),
                             $context
                         );

                        if(($serviceContainer->getSettingsService()->isGatewayServiceProvided || $serviceContainer->getSettingsService()->isProxyUrlProvided) && $payload !== null) {
                            ImpressionUtil::SendImpressionForVariationShown($serviceContainer, $payload, $context, $featureKey);
                        } else {
                            //push this payload to the batch payload
                            if($payload !== null) {
                                $batchPayload[] = $payload;
                            }
                        }
                    }
                }
            }
        }

        // If flag is enabled, store it in data
        if ($isEnabled) {
            (new StorageDecorator())->setDataInStorage(
                array_merge([
                    'featureKey' => $featureKey,
                    'featureId' => $feature->getId(),
                    'context' => $context,
                    'notInHoldoutId' => array_map(function ($holdout) {
                        return $holdout->getId();
                    }, $notMatchedHoldouts),
                ], $passedRulesInformation),
                $storageService,
                $serviceContainer
            );
        } else {
            (new StorageDecorator())->setDataInStorage(
                array_merge([
                    'featureKey' => $featureKey,
                    'featureId' => $feature->getId(),
                    'context' => $context,
                    'notInHoldoutId' => array_map(function ($holdout) {
                        return $holdout->getId();
                    }, $notMatchedHoldouts),
                ]),
                $storageService,
                $serviceContainer
            );
        }

        // Call integration callback, if defined
        $hooksService->set($decision);
        $hooksService->execute($hooksService->get());

         // send debug event, if debugger is enabled
        if ($feature->getIsDebuggerEnabled()) {
            
            $debugEventProps['cg'] = DebuggerCategoryEnum::DECISION;
            // debugEventProps.msg_t = Constants.FLAG_DECISION;
            $debugEventProps['msg_t'] = Constants::FLAG_DECISION_GIVEN;

            $debugEventProps['lt'] = LogLevelEnum::INFO;

            // Update debug event props with decision keys
            $this->updateDebugEventPropsWithDecisionKeys($debugEventProps, $decision);

            // Send debug event
            DebuggerServiceUtil::sendDebugEvent($debugEventProps);
       }

        // Send data for Impact Campaign, if defined
        if ($feature->getImpactCampaign() && $feature->getImpactCampaign()->getCampaignId()) {
            $status = $isEnabled ? 'enabled' : 'disabled';
            $logManager->info(sprintf(
                "Tracking feature: %s being %s for Impact Analysis Campaign for the user %s",
                $featureKey,
                $status,
                $context->getId()
            ));

            if(!$isDebuggerUsed) {
                // Construct payload data for tracking the user
                $networkUtil = new NetworkUtil($serviceContainer);
                $payload = $networkUtil->getTrackUserPayloadData(
                    $serviceContainer->getSettings(),
                    EventEnum::VARIATION_SHOWN,
                    $feature->getImpactCampaign()->getCampaignId(),
                    $isEnabled ? 2 : 1,
                    $context
                );

                if(($serviceContainer->getSettingsService()->isGatewayServiceProvided || $serviceContainer->getSettingsService()->isProxyUrlProvided) && $payload !== null) {
                    // case: Impact Analysis impression sent
                    // set isVariationShownFired to true as the impact analysis impression is sent.
                    $isVariationShownFired = true;
                    ImpressionUtil::SendImpressionForVariationShown($serviceContainer, $payload, $context, $featureKey);
                } else {
                    //push this payload to the batch payload
                    if($payload !== null) {
                        // case: Impact Analysis impression sent
                        // set isVariationShownFired to true as the impact analysis impression is sent.
                        $isVariationShownFired = true;
                        $batchPayload[] = $payload;
                    }
                }
            }
        }

        $variablesForEvaluatedFlag = [];

        if ($experimentVariationToReturn !== null) {
            $variablesForEvaluatedFlag = $experimentVariationToReturn->getVariables();
        } elseif ($rolloutVariationToReturn !== null) {
            $variablesForEvaluatedFlag = $rolloutVariationToReturn->getVariables();
        }
        
        // Send usage tracking call when no primary variationShown event was dispatched.
        // If a primary event was fired, the server already has the usage tracking signal.
        if ($serviceContainer->getSettings()->isTrackingUsageEnabled() && !$isVariationShownFired) {
            ImpressionUtil::createAndSendImpressionForUsageTracking($serviceContainer->getSettings(), $featureKey, $context, $serviceContainer, $batchPayload);
        }

        if(!$serviceContainer->getSettingsService()->isGatewayServiceProvided && !$serviceContainer->getSettingsService()->isProxyUrlProvided && count($batchPayload) > 0) {
            ImpressionUtil::SendImpressionForVariationShownInBatch($batchPayload, $serviceContainer);
        }
    
        return new GetFlagResultUtil($isEnabled, $variablesForEvaluatedFlag, $ruleStatus, $context->getSessionId(), $context->getUUID());
    }

    private function updateIntegrationsDecisionObject(CampaignModel $campaign, VariationModel $variation, array &$passedRulesInformation, array &$decision)
    {
        if ($campaign->getType() === CampaignTypeEnum::ROLLOUT) {
            $passedRulesInformation = array_merge($passedRulesInformation, [
                'rolloutId' => $campaign->getId(),
                'rolloutKey' => $campaign->getKey(),
                'rolloutVariationId' => $variation->getId(),
            ]);
        } else {
            $passedRulesInformation = array_merge($passedRulesInformation, [
                'experimentId' => $campaign->getId(),
                'experimentKey' => $campaign->getKey(),
                'experimentVariationId' => $variation->getId(),
            ]);
        }

        $decision = array_merge($decision, $passedRulesInformation);
    }

    /**
     * Update debug event props with decision keys.
     *
     * @param array &$debugEventProps Debug event props (passed by reference)
     * @param array $decision Decision array
     * @return void
     */
    private function updateDebugEventPropsWithDecisionKeys(array &$debugEventProps, array $decision)
    {
        $decisionKeys = DebuggerServiceUtil::extractDecisionKeys($decision);
        $message = "Flag decision given for feature:{$decision['featureKey']}.";
        
        if (isset($decision['rolloutKey']) && isset($decision['rolloutVariationId'])) {
            $rolloutKeySuffix = substr($decision['rolloutKey'], strlen($decision['featureKey'] . '_'));
            $message .= " Got rollout:{$rolloutKeySuffix} with variation:{$decision['rolloutVariationId']}";
        }
        
        if (isset($decision['experimentKey']) && isset($decision['experimentVariationId'])) {
            $experimentKeySuffix = substr($decision['experimentKey'], strlen($decision['featureKey'] . '_'));
            $message .= " and experiment:{$experimentKeySuffix} with variation:{$decision['experimentVariationId']}";
        }
        
        $debugEventProps['msg'] = $message;
        $debugEventProps = array_merge($debugEventProps, $decisionKeys);
    }
}