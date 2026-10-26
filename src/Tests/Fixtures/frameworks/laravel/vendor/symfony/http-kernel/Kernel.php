<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpKernel;

/**
 * The Kernel is the heart of the Symfony system.
 *
 * It manages an environment made of bundles.
 */
abstract class Kernel implements KernelInterface, RebootableInterface, TerminableInterface
{
    public const VERSION = '7.1.9';
    public const VERSION_ID = 70109;
    public const MAJOR_VERSION = 7;
    public const MINOR_VERSION = 1;
    public const RELEASE_VERSION = 9;
    public const EXTRA_VERSION = '';
}
