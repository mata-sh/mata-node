<?php

declare(strict_types=1);

namespace Mata\Watchers\App;

/**
 * Frameworks and CMSs the node can identify, keyed by their `apps.json` / `/apps` id.
 *
 * Cases are in detection order: products precede the Laravel and Symfony frameworks they can
 * depend on, and Laravel, Drupal, TYPO3 and WordPress precede the Symfony components they ship.
 */
enum Framework: string
{
    case Shopware = 'shopware';
    case Joomla = 'joomla';
    case CakePHP = 'cakephp';
    case Magento = 'magento';
    case Sylius = 'sylius';
    case Craft = 'craft';
    case Statamic = 'statamic';
    case Contao = 'contao';
    case Sulu = 'sulu';
    case Neos = 'neos';
    case October = 'october';
    case Yii = 'yii';
    case CodeIgniter = 'codeigniter';
    case Laravel = 'laravel';
    case Drupal = 'drupal';
    case Typo3 = 'typo3';
    case WordPress = 'wordpress';
    case Symfony = 'symfony';

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
            self::Shopware => ['shopware/core'],
            self::Joomla => ['joomla/joomla-cms'],
            self::CakePHP => ['cakephp/cakephp'],
            self::Magento => ['magento/product-enterprise-edition', 'magento/product-community-edition'],
            self::Sylius => ['sylius/sylius'],
            self::Craft => ['craftcms/cms'],
            self::Statamic => ['statamic/cms'],
            self::Contao => ['contao/core-bundle'],
            self::Sulu => ['sulu/sulu'],
            self::Neos => ['neos/neos'],
            self::October => ['october/rain'],
            self::Yii => ['yiisoft/yii2'],
            self::CodeIgniter => ['codeigniter4/framework'],
            self::Laravel => ['laravel/framework'],
            self::Drupal => ['drupal/core'],
            self::Typo3 => ['typo3/cms-core'],
            self::WordPress => ['roots/wordpress-no-content', 'johnpbloch/wordpress-core', 'roots/wordpress', 'johnpbloch/wordpress'],
            self::Symfony => ['symfony/framework-bundle'],
        };
    }

    /**
     * @return list<string>
     */
    public function versionPackages(): array
    {
        return match ($this) {
            self::Symfony => ['symfony/http-kernel'],
            // A Rain library release does not establish the deployed CMS patch version.
            self::October => [],
            default => $this->identifyingPackages(),
        };
    }
}
