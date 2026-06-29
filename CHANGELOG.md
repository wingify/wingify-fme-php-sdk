# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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