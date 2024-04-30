<?php

/**
 * Woody Library Varnish
 * @author Léo POIROUX
 * @copyright Raccourci Agency 2021
 */

function varnish_flush($name = null)
{
    return do_action('woody_flush_varnish', $name);
}


function woody_flush_varnish($name = null)
{
    return do_action('woody_flush_varnish', $name);
}
