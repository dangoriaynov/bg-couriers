<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgcouriers-pricing.php';

/**
 * The price shown BEFORE a city is chosen used to be a daily figure quoted for a hardcoded 2 kg parcel,
 * whatever was actually in the cart. A customer buying 10 kg was shown the 2 kg price and only found out
 * what they were really paying after picking a city - which is the one moment a shipping price is
 * supposed to stop being a surprise.
 *
 * The reference is now quoted for the cart's own weight and cached per weight. It is bucketed on the way
 * in: a reference price does not need to distinguish 3.01 kg from 3.04 kg, and a key per exact gram
 * would miss the cache on nearly every cart and put a live courier call in front of every page load.
 *
 * @group core
 */
final class ReferenceWeightTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** Half-kilo buckets, always rounded UP: a quote for less than the parcel weighs would understate. */
    public function test_weights_bucket_up_to_the_next_half_kilo(): void {
        $this->assertSame(0.5, BGCouriers_Pricing::reference_weight(0.1));
        $this->assertSame(0.5, BGCouriers_Pricing::reference_weight(0.5));
        $this->assertSame(1.0, BGCouriers_Pricing::reference_weight(0.51));
        $this->assertSame(3.0, BGCouriers_Pricing::reference_weight(2.6));
        $this->assertSame(10.0, BGCouriers_Pricing::reference_weight(10.0));
        $this->assertSame(10.5, BGCouriers_Pricing::reference_weight(10.01));
    }

    /** An empty or nonsense weight still has to produce a usable bucket, not a zero-weight quote. */
    public function test_a_missing_weight_falls_back_to_the_smallest_bucket(): void {
        $this->assertSame(0.5, BGCouriers_Pricing::reference_weight(0.0));
        $this->assertSame(0.5, BGCouriers_Pricing::reference_weight(-3.0));
    }

    /**
     * The cache key has to carry the weight, or the whole point is lost: the 10 kg cart would read
     * whatever the 1 kg cart put there first.
     */
    public function test_the_reference_cache_key_carries_the_weight(): void {
        $light = BGCouriers_Pricing::reference_key('speedy', 'office', 1.0);
        $heavy = BGCouriers_Pricing::reference_key('speedy', 'office', 10.0);
        $this->assertNotSame($light, $heavy);
        $this->assertStringContainsString('speedy', $light);
        $this->assertStringContainsString('office', $light);
        // Same courier, same method, same weight - same key, or nothing would ever be cached.
        $this->assertSame($light, BGCouriers_Pricing::reference_key('speedy', 'office', 1.0));
        // A different method must not share a price either.
        $this->assertNotSame($light, BGCouriers_Pricing::reference_key('speedy', 'automat', 1.0));
    }
}
