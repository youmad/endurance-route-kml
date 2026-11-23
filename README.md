# Endurance Route KML

Map selected KML geometry to Endurance Route content for PHP 8.5.

```bash
composer require endurance/route-kml
```

Supply a completed `PathReader` result and a stable prepared point stream:

```php
use Endurance\RouteKml\KmlGeometryToRoute;

$mapping = new KmlGeometryToRoute()->map($completedRead, $preparedPoints, $sourceReference);
$route = $publisher->create(
    $routeId, $revisionId, $mapping->metadata, $mapping->properties, $mapping->content,
);
```

- `KmlGeometryToRoute` accepts `kml.v2`, preserving LineStrings, GX tracks and
  missing-position breaks as separate parts. Point-only or entirely unlocated
  content cannot create a Route. No interpolation or proximity attachment occurs.
- `KmlPathToRoute` retains its separate `kml.v1` interpretation for LineStrings.
  The source locator and interpretation version must match the chosen mapper.
- Only absolute altitude becomes reported elevation. Relative/clamped values
  remain omissions; no terrain lookup or invented vertical datum is applied.
- Mapping is lazy. Fully consume all content before publication or accepting the
  bounded report. Prepared identity/order/counts are checked. The caller owns
  resources, authorization and publication; a source reference grants no access.

License: [MPL-2.0](LICENSE).
