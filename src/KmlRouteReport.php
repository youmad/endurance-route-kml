<?php

declare(strict_types=1);

namespace Endurance\RouteKml;

final readonly class KmlRouteReport
{
    public const string VERSION = 'kml-path-route.v1';

    public function __construct(
        public int $unattachedPointCount,
        public int $ignoredElementCount,
        public int $omittedAltitudeCount,
        public int $unselectedPlacemarkCount,
    ) {
        foreach (get_object_vars($this) as $count) {
            if ($count < 0) {
                throw new \InvalidArgumentException('KML omission counts must be nonnegative.');
            }
        }
    }

    /** @return list<string> */
    public function notices(): array
    {
        $notices = [];
        foreach (['standalone_points_unattached' => $this->unattachedPointCount,
            'unsupported_elements_omitted' => $this->ignoredElementCount,
            'nonabsolute_altitudes_omitted' => $this->omittedAltitudeCount,
            'other_placemarks_unselected' => $this->unselectedPlacemarkCount] as $notice => $count) {
            if ($count > 0) {
                $notices[] = $notice;
            }
        }

        return $notices;
    }
}
