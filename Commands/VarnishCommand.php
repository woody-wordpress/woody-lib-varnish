<?php

/**
 * Woody Library Varnish
 * @author Léo POIROUX
 * @copyright Raccourci Agency 2021
 */

namespace Woody\Lib\Varnish\Commands;

use Woody\App\Container;

// WP_SITE_KEY=superot wp woody:varnish purge %xkey%
// WP_SITE_KEY=superot wp woody:varnish flush %xkey%

class VarnishCommand
{
    private \Woody\App\Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->VarnishManager = $this->container->get('varnish.manager');
    }

    public function purge($args, $assoc_args)
    {
        [$name] = $args;
        $this->VarnishManager->purge($name);
    }

    public function flush($args, $assoc_args)
    {
        [$name] = $args;
        $this->VarnishManager->purge($name);
    }
}
