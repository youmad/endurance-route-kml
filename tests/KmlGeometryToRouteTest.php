<?php

declare(strict_types=1);

namespace Youmad\Endurance\RouteKml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Kml\Model\KmlPathPoint;
use Youmad\Endurance\Kml\Model\KmlPathReadResult;
use Youmad\Endurance\Kml\PathReader;
use Youmad\Endurance\Route\Application\Port\RouteRevisionWriter;
use Youmad\Endurance\Route\Application\RouteContentValidator;
use Youmad\Endurance\Route\ValueObject\RouteRevisionId;
use Youmad\Endurance\RouteKml\KmlGeometryToRoute;

final class KmlGeometryToRouteTest extends TestCase
{
    /** @return array{KmlPathReadResult,list<KmlPathPoint>} */
    private function selected(?string $body = null): array
    {
        $s = tmpfile();
        self::assertIsResource($s);
        if (null === $body) {
            $xml = file_get_contents(__DIR__.'/Fixture/mixed-path-v2.kml');
            self::assertIsString($xml);
            fwrite($s, $xml);
        } else {
            fwrite($s, '<kml xmlns="http://www.opengis.net/kml/2.2" xmlns:gx="http://www.google.com/kml/ext/2.2">'.$body.'</kml>');
        }
        rewind($s);
        try {
            $g = new PathReader()->objectPoints($s, 'kml.v2/placemark[1]');
            $points = iterator_to_array($g, false);

            return [$g->getReturn(), $points];
        } finally {
            fclose($s);
        }
    }

    public function testMixedSourceWritesIndependentPartsAndFixedOmissionFactsWithoutActivityTiming(): void
    {
        [$source,$points] = $this->selected();
        $mapping = new KmlGeometryToRoute()->map($source, $points, 'source-id');
        $geometry = iterator_to_array($mapping->content->geometry, false);
        self::assertSame([[0, 0], [0, 1], [1, 0], [2, 0], [2, 1], [3, 0], [4, 0], [4, 1]], array_map(static fn ($p) => [$p->position->partIndex, $p->position->pointIndex], $geometry));
        self::assertSame([0.0, -2.0, 5.0, 6.0, 7.0, -5.0, null, null], array_map(static fn ($p) => $p->elevation?->metres, $geometry));
        self::assertSame('Late loop', $mapping->metadata->name);
        self::assertSame('<b>Source text</b>', $mapping->metadata->description);
        self::assertSame('partial', $mapping->properties->geometryCoverage->value);
        self::assertNull($mapping->properties->reportedDistance);
        self::assertSame(['kml-geometry', 'source-id', 'kml.v2/placemark[1]', 'kml-geometry-route.v1'], array_values(get_object_vars($mapping->properties->provenance)));
        self::assertSame([5, 1, 1, 0, 2, 1, 1], array_values(get_object_vars($mapping->report)));
        self::assertSame(['timestamps_omitted', 'geometry_gaps', 'standalone_points_unattached', 'nonabsolute_altitudes_omitted', 'interpolation_not_performed', 'other_placemarks_unselected'], $mapping->report->notices());
        self::assertSame([], iterator_to_array($mapping->content->viaPoints));
        self::assertSame([], $mapping->content->waypoints);
        self::assertNull($geometry[0]->elevation->verticalDatum);
        self::assertSame('kml', $geometry[0]->elevation->source);

        $fresh = new KmlGeometryToRoute()->map($source, $points);
        $writer = $this->createMock(RouteRevisionWriter::class);
        $writer->expects(self::exactly(8))->method('appendGeometry');
        $writer->expects(self::never())->method('appendViaPoint');
        $writer->expects(self::never())->method('publishNew');
        $summary = new RouteContentValidator()->write(RouteRevisionId::generate(), $fresh->content, $writer);
        self::assertSame(8, $summary->geometryPointCount);
        self::assertSame(5, $summary->geometryPartCount);
    }

    public function testOmittedUnobservedContentKeepsCoverageConservative(): void
    {
        [$s,$p] = $this->selected('<Placemark><gx:Track><when>2026-11-06T00:00:00Z</when><gx:coord>1 2 3</gx:coord></gx:Track><Polygon/></Placemark>');
        $m = new KmlGeometryToRoute()->map($s, $p);
        self::assertSame('unknown', $m->properties->geometryCoverage->value);
        self::assertSame(1, $m->report->ignoredElementCount);
        self::assertCount(1, iterator_to_array($m->content->geometry));
    }

    public function testMissingOnlyAndPointOnlySourcesCannotInventAPath(): void
    {
        foreach (['<Point><coordinates>1,2</coordinates></Point>', '<gx:Track><when>2026-11-06T00:00:00Z</when><gx:coord/></gx:Track>'] as $body) {
            [$s,$p] = $this->selected('<Placemark>'.$body.'</Placemark>');
            try {
                new KmlGeometryToRoute()->map($s, $p);
                self::fail('An unobserved path was accepted.');
            } catch (\InvalidArgumentException $failure) {
                self::assertStringContainsString('observed geometry', $failure->getMessage());
            }
        }
    }

    /** @return iterable<string,array{string}> */
    public static function changedPreparations(): iterable
    {
        foreach (['truncated', 'reordered', 'foreign', 'pairing', 'missing-bridge', 'track-bridge', 'out-of-range-track', 'omitted-time'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('changedPreparations')]
    public function testPreparedInputCannotBridgeGapsRebindTimeOrTruncate(string $case): void
    {
        [$s,$p] = $this->selected();
        $index = match ($case) {
            'pairing','missing-bridge' => 4,'track-bridge' => 6,default => 2,
        };
        if ('truncated' === $case) {
            array_pop($p);
        } elseif ('reordered' === $case) {
            $p = array_reverse($p);
        } else {
            $values = get_object_vars($p[$index]);
            $changes = match ($case) {
                'foreign' => ['placemarkIndex' => 2], 'pairing' => ['trackSampleIndex' => 1],
                'missing-bridge' => ['partIndex' => 1, 'pointIndex' => 1], 'track-bridge' => ['partIndex' => 2, 'pointIndex' => 2],
                'out-of-range-track' => ['trackIndex' => 2], 'omitted-time' => ['trackIndex' => null, 'trackSampleIndex' => null, 'when' => null],
                default => throw new \LogicException('Unexpected preparation mutation.'),
            };
            $p[$index] = new KmlPathPoint(...array_replace($values, $changes));
        }
        $this->expectException(\UnexpectedValueException::class);
        iterator_to_array(new KmlGeometryToRoute()->map($s, $p)->content->geometry, false);
    }

    public function testLargeParallelArraysStayBoundedThroughReadingAndMapping(): void
    {
        $s = tmpfile();
        self::assertIsResource($s);
        fwrite($s, '<kml xmlns="http://www.opengis.net/kml/2.2" xmlns:gx="http://www.google.com/kml/ext/2.2"><Placemark><gx:Track>');
        for ($i = 0; $i < 100001; ++$i) {
            fwrite($s, '<when>2026-11-06T00:00:00Z</when>');
        }
        for ($i = 0; $i < 100001; ++$i) {
            fwrite($s, '<gx:coord>1 2 0</gx:coord>');
        }
        fwrite($s, '</gx:Track></Placemark></kml>');
        rewind($s);
        try {
            memory_reset_peak_usage();
            $before = memory_get_usage();
            $objects = new PathReader()->objects($s);
            $object = iterator_to_array($objects, false)[0];
            $document = $objects->getReturn();
            rewind($s);
            $points = new PathReader()->objectPoints($s, $object->locator());
            $mapping = new KmlGeometryToRoute()->map(new KmlPathReadResult($object, $document), $points);
            $count = 0;
            foreach ($mapping->content->geometry as $point) {
                ++$count;
            }
            self::assertSame(100001, $count);
            self::assertSame(100001, $mapping->report->omittedTimestampCount);
            self::assertEquals($object, $points->getReturn()->object);
            self::assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - $before);
        } finally {
            fclose($s);
        }
    }
}
