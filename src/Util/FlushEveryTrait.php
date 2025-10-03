<?php
declare(strict_types=1);

namespace Survos\CoreBundle\Util;

/**
 * Reusable "flush every N items" knob.
 * Default stays at 100 to preserve current behavior until you override it.
 */
trait FlushEveryTrait
{
    private int $flushEvery = 100;

    public function setFlushEvery(int $n): static
    {
        $this->flushEvery = max(1, $n);
        return $this;
    }

    protected function getFlushEvery(): int
    {
        return $this->flushEvery;
    }
}
