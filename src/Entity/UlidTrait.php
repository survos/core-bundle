<?php

namespace Survos\CoreBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * Replaces the integer auto-increment primary key with a ULID string.
 *
 * Usage:
 *   use UlidTrait;
 *
 * The entity must NOT declare its own $id — this trait provides it.
 * Call $this->initUlid() in __construct(), or rely on the PrePersist hook.
 */
trait UlidTrait
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26, unique: true)]
    public private(set) ?string $id = null;

    #[ORM\PrePersist]
    public function initUlid(): void
    {
        if ($this->id === null) {
            $this->id = (string) new Ulid();
        }
    }
}
