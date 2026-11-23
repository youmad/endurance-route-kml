<?php

declare(strict_types=1);

namespace Endurance\RouteKml;

use Endurance\Foundation\ValueObject\Coordinate;
use Endurance\Geo\Elevation;
use Endurance\Geo\ElevationOrigin;
use Endurance\Geo\PathGeometry;
use Endurance\Geo\PathPoint;
use Endurance\Geo\PathPosition;
use Endurance\Kml\Model\AltitudeMode;
use Endurance\Kml\Model\KmlObject;
use Endurance\Kml\Model\KmlObjectReadResult;
use Endurance\Kml\Model\KmlPoint;
use Endurance\Route\Application\RevisionContent;
use Endurance\Route\ValueObject\GeometryCoverage;
use Endurance\Route\ValueObject\RevisionProperties;
use Endurance\Route\ValueObject\RevisionProvenance;
use Endurance\Route\ValueObject\RouteMetadata;

final readonly class KmlPathToRoute
{
    public const string VERSION = 'kml-path-route.v1';

    /** @param iterable<KmlPoint> $points Stable prepared input matching the completely validated source. */
    public function map(KmlObjectReadResult $source, iterable $points, ?string $sourceReference = null): KmlRouteMapping
    {
        $object = $source->object;
        if (0 === $object->lineCount) {
            throw new \InvalidArgumentException('A KML Route requires at least one selected LineString.');
        }

        return new KmlRouteMapping(
            new RevisionContent(new PathGeometry($this->geometry($object, $points))),
            new RouteMetadata($object->name, $object->description),
            new RevisionProperties(0 === $object->ignoredElementCount ? GeometryCoverage::Complete : GeometryCoverage::Unknown,
                provenance: new RevisionProvenance('kml-path', $sourceReference, $object->locator(), self::VERSION)),
            new KmlRouteReport($object->standalonePointCount, $object->ignoredElementCount, $object->omittedAltitudeCount,
                $source->document->placemarkCount - 1),
        );
    }

    /**
     * @param iterable<KmlPoint> $points
     *
     * @return \Generator<int,PathPoint>
     */
    private function geometry(KmlObject $object, iterable $points): \Generator
    {
        $ordinal = $count = $unattached = $omitted = $next = 0;
        $part = -1;
        foreach ($points as $point) {
            if ($point->placemarkIndex !== $object->index || $point->ordinal !== ++$ordinal
                || $ordinal > $object->linePointCount + $object->standalonePointCount) {
                throw new \UnexpectedValueException('KML points do not match the selected Placemark.');
            }
            if (null === $point->partIndex) {
                if (0 !== $point->pointIndex) {
                    throw new \UnexpectedValueException('KML Point must have exactly one coordinate.');
                }
                ++$unattached;
                continue;
            }
            if ($point->partIndex !== $part) {
                if ($point->partIndex !== $part + 1 || ($part >= 0 && $next < 2)) {
                    throw new \UnexpectedValueException('KML parts must preserve source order and valid lines.');
                }
                $part = $point->partIndex;
                $next = 0;
            }
            if ($point->pointIndex !== $next++ || $part >= $object->lineCount || ++$count > $object->linePointCount) {
                throw new \UnexpectedValueException('KML line position does not match the descriptor.');
            }
            $elevation = null;
            if (null !== $point->altitude) {
                if (AltitudeMode::Absolute === $point->altitudeMode) {
                    $elevation = new Elevation($point->altitude, ElevationOrigin::Reported, 'kml');
                } else {
                    ++$omitted;
                }
            }
            yield new PathPoint(new PathPosition($part, $point->pointIndex), new Coordinate($point->latitude, $point->longitude), $elevation);
        }
        if ($count !== $object->linePointCount || $unattached !== $object->standalonePointCount
            || $omitted !== $object->omittedAltitudeCount || $part + 1 !== $object->lineCount || $next < 2) {
            throw new \UnexpectedValueException('Incomplete or changed prepared KML point stream.');
        }
    }
}
