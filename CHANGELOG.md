# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.10.0] - 2026-07-04

### Added

- Support for **Web Testing pre-segmentation** in FME: campaign segmentation can use the `campaignVariation` operand. The SDK evaluates it against **`context.platformVariables.webTestingCampaigns`**, a map of Web Testing campaign ID → variation ID (plain object or JSON string). Supported operand values in settings: `122` (user in campaign), `122_2` (exact variation), `122_!1` (in campaign but not variation 1), `!122` (not in campaign).

## [2.5.0] - 2026-06-16

### Added
- User tracking support: sends a `vwo_feTrackUsage` event when user tracking is enabled for the account and no variation-shown impression was dispatched for the evaluation

## [2.0.0] - 2026-05-30

### Added

- This release introduces Wingify as the primary SDK branding and package namespace

    ```php
    use wingify\Wingify;

    $wingifyClient = Wingify::init([
        'sdkKey' => 'vwo_sdk_key',
        'accountId' => 'vwo_account_id',
    ]);

    // set user context
    $userContext = [ 'id' => 'unique_user_id'];

    // returns a flag object
    $getFlag = $wingifyClient->getFlag('feature_key', $userContext);

    // check if flag is enabled
    $isFlagEnabled = $getFlag['isEnabled'];

    // get variable
    $variableValue = $getFlag->getVariable('stringVar', 'default-value');

    // track event
    $trackRes = $wingifyClient->trackEvent('event-name', $userContext);

    // set Attribute
    $setAttribute = $wingifyClient->setAttribute('attribute-name', 'attribute-value', $userContext);

    ```