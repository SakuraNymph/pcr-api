<?php

namespace Tests\Unit;

use App\Services\PublicUidService;
use InvalidArgumentException;
use Tests\TestCase;

class PublicUidServiceTest extends TestCase
{
    private PublicUidService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PublicUidService::class);
    }

    public function test_special_pool_is_fixed_and_unique(): void
    {
        $specialUids = $this->service->specialUids();

        $this->assertCount(160, $specialUids);
        $this->assertCount(160, array_unique($specialUids));

        foreach ($specialUids as $uid) {
            $this->assertTrue($this->service->isValid($uid), "特殊号 {$uid} 应为合法 UID");
            $this->assertSame(
                PublicUidService::TIER_SPECIAL,
                $this->service->classify($uid),
                "特殊号 {$uid} 应归入特殊档"
            );
        }
    }

    public function test_six_digit_straights_are_the_only_four_digit_exception(): void
    {
        foreach ([
            123456,
            234567,
            345678,
            456789,
            543210,
            654321,
            765432,
            876543,
            987654,
        ] as $uid) {
            $this->assertTrue($this->service->isValid($uid));
            $this->assertSame(PublicUidService::TIER_SPECIAL, $this->service->classify($uid));
        }

        foreach ([400000, 444444, 400001, 104000, 140000, 100400] as $uid) {
            $this->assertFalse($this->service->isValid($uid));
            $this->assertNull($this->service->classify($uid));
        }
    }

    public function test_classifier_recognizes_each_tier(): void
    {
        $cases = [
            111111 => PublicUidService::TIER_SPECIAL,
            888888 => PublicUidService::TIER_SPECIAL,
            520520 => PublicUidService::TIER_SPECIAL,
            100000 => PublicUidService::TIER_PREMIUM,
            100001 => PublicUidService::TIER_PREMIUM,
            223355 => PublicUidService::TIER_PREMIUM,
            100666 => PublicUidService::TIER_PREMIUM,
            689689 => PublicUidService::TIER_PREMIUM,
            100123 => PublicUidService::TIER_PREMIUM,
            100066 => PublicUidService::TIER_AUSPICIOUS,
            123668 => PublicUidService::TIER_AUSPICIOUS,
            100886 => PublicUidService::TIER_AUSPICIOUS,
            123688 => PublicUidService::TIER_AUSPICIOUS,
            100002 => PublicUidService::TIER_NORMAL,
            100003 => PublicUidService::TIER_NORMAL,
            100005 => PublicUidService::TIER_NORMAL,
            100007 => PublicUidService::TIER_NORMAL,
        ];

        foreach ($cases as $uid => $expectedTier) {
            $this->assertSame(
                $expectedTier,
                $this->service->classify($uid),
                "{$uid} 的档位判断不正确"
            );
        }
    }

    public function test_invalid_uid_formats_are_rejected(): void
    {
        foreach ([99999, 1000000, 12345] as $uid) {
            $this->assertFalse($this->service->isValid($uid));
            $this->assertNull($this->service->classify($uid));
        }
    }

    public function test_generate_only_returns_premium_or_auspicious(): void
    {
        $tierCounts = [
            PublicUidService::TIER_PREMIUM => 0,
            PublicUidService::TIER_AUSPICIOUS => 0,
        ];

        for ($index = 0; $index < 2000; $index++) {
            $uid = $this->service->generate();

            $this->assertTrue($this->service->isValid($uid));
            $this->assertStringNotContainsString('4', (string) $uid);

            $tier = $this->service->classify($uid);
            $this->assertArrayHasKey($tier, $tierCounts);
            $tierCounts[$tier]++;
        }

        $this->assertGreaterThan(0, $tierCounts[PublicUidService::TIER_PREMIUM]);
        $this->assertGreaterThan(0, $tierCounts[PublicUidService::TIER_AUSPICIOUS]);
    }

    public function test_auto_generation_rejects_reserved_and_normal_tiers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->generateForTier(PublicUidService::TIER_SPECIAL);
    }

    public function test_auto_generation_rejects_normal_tier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->generateForTier(PublicUidService::TIER_NORMAL);
    }

    public function test_default_weights_are_one_to_nine_between_premium_and_auspicious(): void
    {
        $this->assertSame(0, (int) config('public_uid.weights.special'));
        $this->assertSame(10, (int) config('public_uid.weights.premium'));
        $this->assertSame(90, (int) config('public_uid.weights.auspicious'));
        $this->assertSame(0, (int) config('public_uid.weights.normal'));
    }
}
