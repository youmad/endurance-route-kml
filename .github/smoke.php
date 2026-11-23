<?php

declare(strict_types=1);

/** @param Closure(bool): void $ensure */
return static function (Closure $ensure, string $autoload, string $package): void {
    $stream = fopen('php://temp', 'w+b');
    try {
        fwrite($stream, '<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark><LineString><altitudeMode>absolute</altitudeMode><coordinates>179,0,0 -179,0,-2</coordinates></LineString></Placemark></kml>');
        rewind($stream);
        $points = new Youmad\Endurance\Kml\PathReader()->objectPoints($stream, 'kml.v2/placemark[1]');
        $read = iterator_to_array($points, false);
        $mapping = new Youmad\Endurance\RouteKml\KmlGeometryToRoute()->map($points->getReturn(), $read);
        $geometry = iterator_to_array($mapping->content->geometry, false);
        $ensure(2 === count($geometry) && 0.0 === $geometry[0]->elevation?->metres && -2.0 === $geometry[1]->elevation?->metres);
    } finally {
        fclose($stream);
    }
};
