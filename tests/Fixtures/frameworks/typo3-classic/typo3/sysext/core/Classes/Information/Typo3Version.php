<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Core\Information;

use TYPO3\CMS\Core\SingletonInterface;

class Typo3Version implements SingletonInterface
{
    protected const VERSION = '12.4.20';
    protected const BRANCH = '12.4';

    public function getVersion(): string
    {
        return static::VERSION;
    }

    public function getBranch(): string
    {
        return static::BRANCH;
    }
}
