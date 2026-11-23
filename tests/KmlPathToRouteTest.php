<?php

declare(strict_types=1);

namespace Youmad\Endurance\RouteKml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Kml\Model\KmlObjectReadResult;
use Youmad\Endurance\Kml\Model\KmlPoint;
use Youmad\Endurance\Kml\Reader;
use Youmad\Endurance\RouteKml\KmlPathToRoute;

final class KmlPathToRouteTest extends TestCase
{
    /** @return array{KmlObjectReadResult,list<KmlPoint>} */
    private function selected(string $body): array
    {
        $s = fopen('php://temp', 'w+b');
        self::assertIsResource($s);
        fwrite($s, '<kml xmlns="http://www.opengis.net/kml/2.2">'.$body.'</kml>');
        rewind($s);
        try {
            $g = new Reader()->objectPoints($s, 'kml.v1/placemark[1]');
            $points = iterator_to_array($g, false);

            return [$g->getReturn(), $points];
        } finally {
            fclose($s);
        }
    }

    public function testMapsPartsWithoutJoiningPointsOrInventingHeightTimingDistance(): void
    {
        [$source,$points] = $this->selected('<Placemark><name>Loop</name><description>Stored text</description><MultiGeometry>'
            .'<LineString><altitudeMode>absolute</altitudeMode><coordinates>0,0,0 1,1,-2</coordinates></LineString>'
            .'<Point><coordinates>10,10,999</coordinates></Point>'
            .'<LineString><coordinates>1,1,800 2,2,900</coordinates></LineString></MultiGeometry></Placemark><Placemark/>');
        $m = new KmlPathToRoute()->map($source, $points, 'source-id');
        $path = iterator_to_array($m->content->geometry, false);
        self::assertSame([[0, 0], [0, 1], [1, 0], [1, 1]], array_map(fn ($p) => [$p->position->partIndex, $p->position->pointIndex], $path));
        self::assertSame([0.0, -2.0, null, null], array_map(fn ($p) => $p->elevation?->metres, $path));
        self::assertNull($path[0]->elevation->verticalDatum);
        self::assertSame('kml', $path[0]->elevation->source);
        self::assertSame('Loop', $m->metadata->name);
        self::assertSame('Stored text', $m->metadata->description);
        self::assertNull($m->properties->reportedDistance);
        self::assertSame('complete', $m->properties->geometryCoverage->value);
        self::assertSame(['kml-path', 'source-id', 'kml.v1/placemark[1]', KmlPathToRoute::VERSION], array_values(get_object_vars($m->properties->provenance)));
        self::assertSame(['standalone_points_unattached', 'nonabsolute_altitudes_omitted', 'other_placemarks_unselected'], $m->report->notices());
        self::assertSame([], iterator_to_array($m->content->viaPoints));
        self::assertSame([], $m->content->waypoints);
    }

    public function testUnobservedGeometryMakesCoverageConservativeAndIsReported(): void
    {
        [$s,$p] = $this->selected('<Placemark><MultiGeometry><LineString><coordinates>0,0 1,1</coordinates></LineString>'
            .'<Polygon><outerBoundaryIs/></Polygon></MultiGeometry></Placemark>');
        $m = new KmlPathToRoute()->map($s, $p);
        self::assertSame('unknown', $m->properties->geometryCoverage->value);
        self::assertSame(['unsupported_elements_omitted'], $m->report->notices());
        self::assertCount(2, iterator_to_array($m->content->geometry));
    }

    public function testPointOnlyPlacemarkHasNoInventedRoute(): void
    {
        [$s,$p] = $this->selected('<Placemark><Point><coordinates>1,2</coordinates></Point></Placemark>');
        $this->expectException(\InvalidArgumentException::class);
        new KmlPathToRoute()->map($s, $p);
    }

    /** @return iterable<string,array{string}> */
    public static function changedPreparations(): iterable
    {
        yield 'truncated' => ['truncated'];
        yield 'reordered' => ['reordered'];
        yield 'foreign' => ['foreign'];
    }

    #[DataProvider('changedPreparations')]
    public function testChangedPreparedStreamCannotCompleteMapping(string $case): void
    {
        [$s, $p] = $this->selected('<Placemark><LineString><coordinates>1,2 3,4</coordinates></LineString></Placemark>');
        $changed = match ($case) {
            'truncated' => [$p[0]],
            'reordered' => array_reverse($p),
            'foreign' => [new KmlPoint(2, 1, 0, 0, 2, 1, null, $p[0]->altitudeMode), $p[1]],
            default => throw new \LogicException('Unknown prepared-input mutation.'),
        };
        $this->expectException(\UnexpectedValueException::class);
        iterator_to_array(new KmlPathToRoute()->map($s, $changed)->content->geometry);
    }

    public function testOneHugeCoordinateTextNodeStaysBoundedThroughReadAndMap(): void
    {
        $s = tmpfile();
        self::assertIsResource($s);
        fwrite($s, '<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark><LineString><coordinates>');
        for ($i = 0; $i < 100001; ++$i) {
            fwrite($s, '1,2,0 ');
        }fwrite($s, '</coordinates></LineString></Placemark></kml>');
        rewind($s);
        try {
            $objects = new Reader()->objects($s);
            $o = iterator_to_array($objects, false)[0];
            $doc = $objects->getReturn();
            rewind($s);
            $points = new Reader()->objectPoints($s, $o->locator());
            $count = 0;
            memory_reset_peak_usage();
            $before = memory_get_usage();
            foreach (new KmlPathToRoute()->map(new KmlObjectReadResult($o, $doc), $points)->content->geometry as $point) {
                ++$count;
            }
            self::assertSame(100001, $count);
            self::assertSame($o->linePointCount, $points->getReturn()->object->linePointCount);
            self::assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - $before);
        } finally {
            fclose($s);
        }
    }
}
