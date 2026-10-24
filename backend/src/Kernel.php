<?php

declare(strict_types=1);

namespace App;

use App\Security\ProductionConfigGuard;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        if ('prod' === $this->environment && !$this->booted) {
            ProductionConfigGuard::assertSafe(ProductionConfigGuard::currentEnv());
        }
        parent::boot();
    }
}
