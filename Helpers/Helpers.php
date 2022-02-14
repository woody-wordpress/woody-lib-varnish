<?php

/**
 * Woody Library Varnish
 * @author Léo POIROUX
 * @copyright Raccourci Agency 2021
 */

function varnish_flush($name = null)
{
    return do_action('woody_varnish_flush', $name);
}
