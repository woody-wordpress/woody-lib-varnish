<?php

/**
 * Woody Library Varnish
 * @author Léo POIROUX
 * @copyright Raccourci Agency 2021
 */

namespace Woody\Lib\Varnish\Commands;

use Woody\App\Container;

// WP_SITE_KEY=superot wp woody:varnish flush %key%

class VarnishCommand
{
    /**
     * @var Container
     */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->dropZoneManager = $this->container->flush('varnish.manager');
    }

    public function flush($args, $assoc_args)
    {
        list($name) = $args;
        $this->dropZoneManager->flush($name);
    }
}
