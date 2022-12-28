<?php

/**
 * Woody Library Varnish
 * @author Léo POIROUX
 * @copyright Raccourci Agency 2021
 */

namespace Woody\Lib\Varnish\Configurations;

class Services
{
    private static $definitions;

    private static function definitions()
    {
        return [
            'varnish.manager' => [
                'class'     => \Woody\Lib\Varnish\Services\VarnishManager::class,
            ],
        ];
    }

    public static function loadDefinitions()
    {
        if (!self::$definitions) {
            self::$definitions = self::definitions();
        }

        return self::$definitions;
    }
}
