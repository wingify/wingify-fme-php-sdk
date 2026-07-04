<?php

namespace wingify;

use PHPUnit\Framework\TestCase;
use wingify\Models\User\ContextModel;
use wingify\Services\ServiceContainer;
use wingify\Packages\SegmentationEvaluator\Utils\WebTestingSegmentUtil;
use wingify\Packages\SegmentationEvaluator\Evaluators\SegmentEvaluator;

class WebTestingCampaignVariationTest extends TestCase
{
    public function testEvaluateWebTestingCampaignVariationCVMatches()
    {
        $map = ['1' => '1', '2' => '2'];
        $result = WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('1_1', $map);
        $this->assertTrue($result['result']);
        $this->assertFalse($result['invalidFormat']);
    }

    public function testEvaluateWebTestingCampaignVariationCVFalse()
    {
        $map = ['1' => '1', '2' => '2'];
        $result = WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('1_2', $map);
        $this->assertFalse($result['result']);
    }

    public function testEvaluateWebTestingCampaignVariationCVFalseNotInCampaign()
    {
        $map = ['1' => '1', '2' => '2'];
        $result = WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('99_1', $map);
        $this->assertFalse($result['result']);
    }

    public function testEvaluateWebTestingCampaignVariationCNotVMoves()
    {
        $map = ['1' => '1', '2' => '2'];
        $result = WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('1_!2', $map);
        $this->assertTrue($result['result']);
    }

    public function testEvaluateWebTestingCampaignVariationCNotVFalse()
    {
        $map = ['1' => '1', '2' => '2'];
        $result = WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('1_!1', $map);
        $this->assertFalse($result['result']);
    }

    public function testEvaluateWebTestingCampaignVariationCNotVFalseNotInCampaign()
    {
        $map = ['1' => '1', '2' => '2'];
        $result = WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('99_!1', $map);
        $this->assertFalse($result['result']);
    }

    public function testEvaluateWebTestingCampaignVariationNotCTrue()
    {
        $map = ['1' => '1', '2' => '2'];
        $result = WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('!99', $map);
        $this->assertTrue($result['result']);
    }

    public function testEvaluateWebTestingCampaignVariationNotCFalse()
    {
        $map = ['1' => '1', '2' => '2'];
        $result = WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('!1', $map);
        $this->assertFalse($result['result']);
    }

    public function testEvaluateWebTestingCampaignVariationNullMap()
    {
        $result = WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('!1', null);
        $this->assertTrue($result['result']);
        
        $result2 = WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('1_1', null);
        $this->assertFalse($result2['result']);
    }

    public function testEvaluateWebTestingCampaignVariationInvalid()
    {
        $map = ['1' => '1', '2' => '2'];
        $result = WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('bogus', $map);
        $this->assertFalse($result['result']);
        $this->assertTrue($result['invalidFormat']);
    }

    public function testEvaluateWebTestingCampaignVariationMultiDigit()
    {
        $map = ['122' => '4'];
        $this->assertTrue(WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('122_4', $map)['result']);
        $this->assertTrue(WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('122_!1', $map)['result']);
        $this->assertFalse(WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('!122', $map)['result']);
    }

    public function testEvaluateWebTestingCampaignVariationCAlone()
    {
        $this->assertTrue(WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('100', ['100' => '1'])['result']);
        $this->assertTrue(WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('100', ['100' => '9'])['result']);
        $this->assertFalse(WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('100', [])['result']);
        $this->assertFalse(WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('100', ['99' => '1'])['result']);
        $this->assertFalse(WebTestingSegmentUtil::evaluateWebTestingCampaignVariation('100', ['1' => '1', '2' => '2'])['result']);
    }

    public function testNormalizeWebTestingCampaignsMap()
    {
        $map = [129 => 1, '14' => 2];
        $result = WebTestingSegmentUtil::normalizeWebTestingCampaignsMap($map);
        $this->assertEquals(['129' => '1', '14' => '2'], $result);
    }

    public function testParseWebTestingCampaignsFromContextValidJson()
    {
        $context = new ContextModel();
        $context->modelFromDictionary([
            'id' => 'u1',
            'platformVariables' => [
                'webTestingCampaigns' => '{"1": "1"}'
            ]
        ]);
        $sc = $this->createMock(ServiceContainer::class);
        $res = WebTestingSegmentUtil::parseWebTestingCampaignsFromContext($context, $sc);
        $this->assertEquals(['1' => '1'], $res);
    }
}
