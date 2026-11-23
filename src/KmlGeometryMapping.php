<?php

declare(strict_types=1);

namespace Youmad\Endurance\RouteKml;

use Youmad\Endurance\Route\Application\RevisionContent;
use Youmad\Endurance\Route\ValueObject\RevisionProperties;
use Youmad\Endurance\Route\ValueObject\RouteMetadata;

final readonly class KmlGeometryMapping
{
    public function __construct(public RevisionContent $content, public RouteMetadata $metadata,
        public RevisionProperties $properties, public KmlGeometryReport $report)
    {
    }
}
