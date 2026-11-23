<?php

declare(strict_types=1);

namespace Endurance\RouteKml;

use Endurance\Route\Application\RevisionContent;
use Endurance\Route\ValueObject\RevisionProperties;
use Endurance\Route\ValueObject\RouteMetadata;

final readonly class KmlGeometryMapping
{
    public function __construct(public RevisionContent $content, public RouteMetadata $metadata,
        public RevisionProperties $properties, public KmlGeometryReport $report)
    {
    }
}
