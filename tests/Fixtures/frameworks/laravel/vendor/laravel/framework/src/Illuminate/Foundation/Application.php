<?php

namespace Illuminate\Foundation;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Support\Traits\Macroable;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class Application extends Container implements ApplicationContract, HttpKernelInterface
{
    use Macroable;

    /**
     * The Laravel framework version.
     *
     * @var string
     */
    const VERSION = '11.34.2';
}
