<?php

declare(strict_types=1);

namespace Mata\Watchers\App;

/**
 * Frameworks and CMSs the node can identify, keyed by their `apps.json` / `/apps` id.
 */
enum Framework: string
{
    case Laravel = 'laravel';
    case Symfony = 'symfony';
    case WordPress = 'wordpress';
    case Drupal = 'drupal';
    case Typo3 = 'typo3';

    /**
     * Detection order. Laravel, Drupal, TYPO3 and Composer-installed WordPress pull in
     * Symfony components, so Symfony is only considered once none of them matched.
     */
    public const DETECTION_ORDER = [self::Laravel, self::Drupal, self::Typo3, self::WordPress, self::Symfony];

    /**
     * Composer packages whose presence identifies the framework. The first one installed
     * also carries the running version.
     *
     * Symfony is identified by `framework-bundle`, which Laravel and Drupal do not use, but
     * versioned by `http-kernel`, whose `Kernel::VERSION` is what `bin/console --version` shows.
     *
     * @return list<string>
     */
    public function identifyingPackages(): array
    {
        return match ($this) {
            self::Laravel => ['laravel/framework'],
            self::Symfony => ['symfony/framework-bundle'],
            self::WordPress => ['roots/wordpress-no-content', 'johnpbloch/wordpress-core', 'roots/wordpress', 'johnpbloch/wordpress'],
            self::Drupal => ['drupal/core'],
            self::Typo3 => ['typo3/cms-core'],
        };
    }

    /**
     * @return list<string>
     */
    public function versionPackages(): array
    {
        return $this === self::Symfony ? ['symfony/http-kernel'] : $this->identifyingPackages();
    }
}
