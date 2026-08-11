<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Listing\LandBoundaryService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The measurements a buyer sees.
 *
 * These are pure geometry, so they run without the database. The reference
 * figures come from squares of known side length at Dar es Salaam's latitude —
 * if the projection were mishandled the error would be a factor of
 * 1/cos(-6.8°) ≈ 1.007, which is small enough to look plausible and is exactly
 * why it needs asserting rather than eyeballing.
 */
class LandBoundaryServiceTest extends TestCase
{
    private LandBoundaryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LandBoundaryService;
    }

    #[Test]
    public function it_measures_a_hundred_metre_square_as_one_hectare(): void
    {
        // 100 m north-south is 100/111320 degrees of latitude; 100 m east-west
        // is that divided by cos(latitude).
        $lat = -6.8;
        $dLat = 100 / 111_320;
        $dLng = 100 / (111_320 * cos(deg2rad($lat)));

        $ring = [
            [39.28, $lat],
            [39.28 + $dLng, $lat],
            [39.28 + $dLng, $lat + $dLat],
            [39.28, $lat + $dLat],
        ];

        $metrics = $this->service->measure([$this->service->normaliseRing($ring)]);

        /*
         * One hectare within 0.5%.
         *
         * The residual ~0.2% is real and expected: the fixture sizes its square
         * with the EQUATORIAL degree (111,320 m) while the service measures on
         * a mean-radius sphere (111,195 m). Tightening this would mean picking
         * one radius for both, which would make the test agree with the code by
         * construction and stop testing anything. A misprojected longitude —
         * the failure this guards against — would be off by 12%, not 0.2%.
         */
        $this->assertEqualsWithDelta(10_000, $metrics['area_sqm'], 50);
        $this->assertEqualsWithDelta(400, $metrics['perimeter_m'], 2);
    }

    #[Test]
    public function it_reports_area_in_the_units_land_is_traded_in(): void
    {
        $this->assertSame('800 m²', $this->service->areaSummary(800)['display']);
        $this->assertStringContainsString('acres', $this->service->areaSummary(4046.86)['display']);
        $this->assertStringContainsString('ha', $this->service->areaSummary(120_000)['display']);

        $this->assertEqualsWithDelta(1.0, $this->service->areaSummary(4046.8564224)['acres'], 0.0001);
    }

    #[Test]
    public function it_closes_an_open_ring_and_drops_duplicate_corners(): void
    {
        $ring = $this->service->normaliseRing([
            [39.28, -6.80],
            [39.28, -6.80],   // a double-click
            [39.29, -6.80],
            [39.29, -6.81],
        ]);

        $this->assertCount(4, $ring, 'three distinct corners plus the closing point');
        $this->assertSame($ring[0], $ring[3]);
    }

    #[Test]
    public function it_rejects_an_outline_that_crosses_itself(): void
    {
        // A bow tie: the diagonal ordering makes the two lobes cancel, so the
        // area formula would under-report rather than fail loudly.
        $bowTie = [
            [39.28, -6.80],
            [39.29, -6.81],
            [39.29, -6.80],
            [39.28, -6.81],
        ];

        $this->assertTrue($this->service->selfIntersects($bowTie));
    }

    #[Test]
    public function it_accepts_a_simple_rectangle(): void
    {
        $this->assertFalse($this->service->selfIntersects([
            [39.28, -6.80],
            [39.29, -6.80],
            [39.29, -6.81],
            [39.28, -6.81],
        ]));
    }

    #[Test]
    public function it_puts_the_centroid_in_the_middle_of_a_rectangle(): void
    {
        $metrics = $this->service->measure([$this->service->normaliseRing([
            [39.28, -6.80],
            [39.30, -6.80],
            [39.30, -6.82],
            [39.28, -6.82],
        ])]);

        $this->assertEqualsWithDelta(39.29, $metrics['centroid'][0], 1e-6);
        $this->assertEqualsWithDelta(-6.81, $metrics['centroid'][1], 1e-6);
    }

    #[Test]
    public function a_hole_is_subtracted_from_the_outer_ring(): void
    {
        $outer = $this->service->normaliseRing([
            [39.28, -6.80], [39.30, -6.80], [39.30, -6.82], [39.28, -6.82],
        ]);

        $hole = $this->service->normaliseRing([
            [39.285, -6.805], [39.295, -6.805], [39.295, -6.815], [39.285, -6.815],
        ]);

        $whole = $this->service->measure([$outer])['area_sqm'];
        $withHole = $this->service->measure([$outer, $hole])['area_sqm'];

        $this->assertLessThan($whole, $withHole);
        $this->assertGreaterThan(0, $withHole);
    }

    #[Test]
    public function collinear_points_enclose_nothing(): void
    {
        $metrics = $this->service->measure([$this->service->normaliseRing([
            [39.28, -6.80], [39.29, -6.80], [39.30, -6.80],
        ])]);

        $this->assertEqualsWithDelta(0.0, $metrics['area_sqm'], 0.5);
    }
}
