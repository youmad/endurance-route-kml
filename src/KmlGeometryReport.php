<?php

declare(strict_types=1);

namespace Youmad\Endurance\RouteKml;

final readonly class KmlGeometryReport
{
    public const string VERSION = 'kml-geometry-route.v1';

    public function __construct(
        public int $omittedTimestampCount,
        public int $missingCoordinateCount,
        public int $unattachedPointCount,
        public int $ignoredElementCount,
        public int $omittedAltitudeCount,
        public int $interpolationRequestCount,
        public int $unselectedPlacemarkCount,
    ) {
        foreach (get_object_vars($this) as $count) {
            if ($count < 0) {
                throw new \InvalidArgumentException('KML interpretation counts must be nonnegative.');
            }
        }
    }

    /** @return list<string> */
    public function notices(): array
    {
        $notices = [];
        foreach (['timestamps_omitted' => $this->omittedTimestampCount, 'geometry_gaps' => $this->missingCoordinateCount,
            'standalone_points_unattached' => $this->unattachedPointCount, 'unsupported_elements_omitted' => $this->ignoredElementCount,
            'nonabsolute_altitudes_omitted' => $this->omittedAltitudeCount, 'interpolation_not_performed' => $this->interpolationRequestCount,
            'other_placemarks_unselected' => $this->unselectedPlacemarkCount] as $notice => $count) {
            if ($count > 0) {
                $notices[] = $notice;
            }
        }

        return $notices;
    }
}
