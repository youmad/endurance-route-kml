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
use Endurance\Kml\Model\KmlPathObject;
use Endurance\Kml\Model\KmlPathPoint;
use Endurance\Kml\Model\KmlPathReadResult;
use Endurance\Route\Application\RevisionContent;
use Endurance\Route\ValueObject\GeometryCoverage;
use Endurance\Route\ValueObject\RevisionProperties;
use Endurance\Route\ValueObject\RevisionProvenance;
use Endurance\Route\ValueObject\RouteMetadata;

/** kml.v2 interpretation; kml.v1 locators must continue through KmlPathToRoute. */
final readonly class KmlGeometryToRoute
{
    public const string VERSION = KmlGeometryReport::VERSION;

    /** @param iterable<KmlPathPoint> $points Stable caller-owned preparation of completely verified input. */
    public function map(KmlPathReadResult $source, iterable $points, ?string $sourceReference = null): KmlGeometryMapping
    {
        $o = $source->object;
        if (0 === $o->geometryPointCount) {
            throw new \InvalidArgumentException('A KML Route needs observed geometry.');
        }
        $coverage = $o->ignoredElementCount > 0 ? GeometryCoverage::Unknown : ($o->missingCoordinateCount > 0 ? GeometryCoverage::Partial : GeometryCoverage::Complete);

        return new KmlGeometryMapping(new RevisionContent(new PathGeometry($this->geometry($o, $points))),
            new RouteMetadata($o->name, $o->description),
            new RevisionProperties($coverage, provenance: new RevisionProvenance('kml-geometry', $sourceReference, $o->locator(), self::VERSION)),
            new KmlGeometryReport($o->trackSampleCount, $o->missingCoordinateCount, $o->standalonePointCount, $o->ignoredElementCount,
                $o->omittedAltitudeCount, $o->interpolationRequestCount, $source->document->placemarkCount - 1));
    }

    /** @param iterable<KmlPathPoint> $points
     * @return \Generator<int,PathPoint>
     */
    private function geometry(KmlPathObject $o, iterable $points): \Generator
    {
        $ordinal = $geometry = $standalone = $missing = $timed = $omitted = 0;
        $part = -1;
        $nextPoint = 0;
        $partMode = null;
        $closed = true;
        $currentTrack = null;
        $lastTrack = -1;
        $nextSample = 0;
        foreach ($points as $point) {
            if ($point->placemarkIndex !== $o->index || $point->ordinal !== ++$ordinal
                || $ordinal > $o->geometryPointCount + $o->standalonePointCount + $o->missingCoordinateCount) {
                throw new \UnexpectedValueException('Prepared KML source identity or ordinal changed.');
            }
            if ($point->trackIndex !== $currentTrack) {
                $closed = true;
                if (null !== $point->trackIndex) {
                    if ($point->trackIndex <= $lastTrack || $point->trackIndex >= $o->trackCount) {
                        throw new \UnexpectedValueException('Prepared KML Track order changed.');
                    }
                    $lastTrack = $point->trackIndex;
                    $nextSample = 0;
                }
                $currentTrack = $point->trackIndex;
            }
            if (null !== $point->trackIndex) {
                if ($point->trackSampleIndex !== $nextSample++) {
                    throw new \UnexpectedValueException('Prepared KML time/position pairing changed.');
                }
                ++$timed;
            }
            if (null === $point->partIndex) {
                $closed = true;
                if (null === $point->latitude) {
                    ++$missing;
                } else {
                    ++$standalone;
                }
                continue;
            }
            if ($point->partIndex !== $part) {
                if ($point->partIndex !== $part + 1) {
                    throw new \UnexpectedValueException('Prepared KML part order changed.');
                }
                $part = $point->partIndex;
                $nextPoint = 0;
                $partMode = $point->altitudeMode;
                $closed = false;
            }
            if ($closed || $point->pointIndex !== $nextPoint++ || $point->altitudeMode !== $partMode
                || null === $point->latitude || null === $point->longitude) {
                throw new \UnexpectedValueException('Prepared KML gap or geometry changed.');
            }
            $elevation = null;
            if (null !== $point->altitude) {
                if (AltitudeMode::Absolute === $point->altitudeMode) {
                    $elevation = new Elevation($point->altitude, ElevationOrigin::Reported, 'kml');
                } else {
                    ++$omitted;
                }
            }
            ++$geometry;
            yield new PathPoint(new PathPosition($part, $point->pointIndex), new Coordinate($point->latitude, $point->longitude), $elevation);
        }
        if ($geometry !== $o->geometryPointCount || $part + 1 !== $o->geometryPartCount || $standalone !== $o->standalonePointCount
            || $missing !== $o->missingCoordinateCount || $timed !== $o->trackSampleCount || $omitted !== $o->omittedAltitudeCount) {
            throw new \UnexpectedValueException('Incomplete or changed prepared KML path stream.');
        }
    }
}
