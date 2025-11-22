<?php
declare(strict_types=1);

namespace Survos\CoreBundle\Service;

use Doctrine\Persistence\ManagerRegistry;
use RuntimeException;

/**
 * Resolve entity class names from short names using Doctrine metadata.
 *
 * Accepts:
 *   - Fully-qualified class name (e.g. "App\Entity\Movie")
 *   - Short name (e.g. "Movie")
 *   - Lowercased short name (e.g. "movie")
 *
 * Returns the FQCN or throws if not found.
 */
final class EntityClassResolver
{
    public function __construct(
        private ManagerRegistry $registry,
    ) {
    }

    public function resolve(string $name): string
    {
        // If it's already a loadable class, we're done.
        if (\class_exists($name)) {
            return $name;
        }

        $short = \ucfirst($name);

        foreach ($this->registry->getManagers() as $em) {
            $metadata = $em->getMetadataFactory()->getAllMetadata();

            foreach ($metadata as $meta) {
                $class = $meta->getName();

                // Compare by basename
                $basename = \basename(\str_replace('\\', '/', $class));
                if ($basename === $short) {
                    return $class;
                }
            }
        }

        throw new RuntimeException(sprintf(
            'Cannot resolve entity class for "%s". Try using the full FQCN (e.g. "App\\Entity\\Wam").',
            $name
        ));
    }
}
