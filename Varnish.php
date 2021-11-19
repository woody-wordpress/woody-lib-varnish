<?php

/**
 * Woody Library Varnish
 * @author Léo POIROUX
 * @copyright Raccourci Agency 2021
 */

namespace Woody\Lib\Varnish;

use Woody\App\Container;
use Woody\Modules\Module;
use Woody\Services\ParameterManager;
use Woody\Lib\Varnish\Commands\VarnishCommand;

final class Varnish extends Module
{
    protected static $key = 'woody_lib_varnish';

    public function initialize(ParameterManager $parameters, Container $container)
    {
        define('WOODY_LIB_VARNISH_VERSION', '1.0.0');
        define('WOODY_LIB_VARNISH_ROOT', __FILE__);
        define('WOODY_LIB_VARNISH_DIR_ROOT', dirname(WOODY_LIB_VARNISH_ROOT));

        parent::initialize($parameters, $container);
        $this->VarnishManager = $this->container->get('varnish.manager');
        require_once WOODY_LIB_VARNISH_DIR_ROOT . '/Helpers/Helpers.php';
    }

    public function registerCommands()
    {
        \WP_CLI::add_command('woody:varnish', new VarnishCommand($this->container));
    }

    public static function dependencyServiceDefinitions()
    {
        return \Woody\Lib\Varnish\Configurations\Services::loadDefinitions();
    }

    public function subscribeHooks()
    {
        register_activation_hook(WOODY_LIB_VARNISH_ROOT, [$this, 'activate']);
        register_deactivation_hook(WOODY_LIB_VARNISH_ROOT, [$this, 'deactivate']);

        add_action('init', [$this, 'init']);
        add_filter('query_vars', [$this, 'queryVars']);
        add_action('template_redirect', [$this, 'forceLogout'], 1);
        add_action('woody_flush_varnish', [$this, 'flush'], 10, 2);

        // Send headers
        if (!is_admin()) {
            add_action('wp', [$this->VarnishManager, 'sendHeaders'], 1000000);
            add_action('wp', [$this->VarnishManager, 'force_logout'], 1000000);
        }

        // Logged in cookie
        add_action('wp_login', [$this->VarnishManager, 'wp_login'], 1000000);
        add_action('wp_logout', [$this->VarnishManager, 'wp_logout'], 1000000);

        // Register events to purge post
        foreach ($this->get_register_events() as $event) {
            add_action($event, [$this, 'flush'], 10, 2);
        }
    }

    // ------------------------
    // ADMIN BAR
    // ------------------------
    public function init()
    {
        if (is_admin()) {
            $user = wp_get_current_user();
            if (in_array('administrator', $user->roles)) {
                add_action('admin_bar_menu', [$this, 'flush_admin_bar_menu'], 100);
                if (isset($_GET['flush_admin_varnish']) && check_admin_referer('varnish')) {
                    $this->flush_admin_varnish();
                }
            }
        }

        add_rewrite_rule('woody-logout', 'index.php?woody_logout=true', 'top');
    }

    public function queryVars($qvars)
    {
        $qvars[] = 'woody_logout';
        return $qvars;
    }

    public function forceLogout($qvars)
    {
        $woody_logout = get_query_var('woody_logout');
        if (!empty($woody_logout)) {
            $this->VarnishManager->woody_logout();
        }
    }

    public function flush_admin_bar_menu($admin_bar)
    {
        $admin_bar->add_menu(array(
            'id'    => 'flush-varnish',
            'title' => '<span class="ab-icon dashicons dashicons-cloud-saved" style="top:2px;" aria-hidden="true"></span> Flush Varnish',
            'href'  => wp_nonce_url(add_query_arg('flush_admin_varnish', 1), 'varnish'),
            'meta'  => array(
                'title' => 'Flush Varnish',
            )
        ));
    }

    public function flush_admin_varnish()
    {
        $this->VarnishManager->purge();
        add_action('admin_notices', [$this, 'flush_message']);
    }

    public function flush_message()
    {
        echo '<div id="message" class="updated fade"><p><strong>Varnish is flushed</strong></p></div>';
    }

    // ------------------------
    // PURGE / BAN
    // ------------------------
    public function flush($xkey = null)
    {
        $this->purge($xkey);
    }

    public function purge($xkey = null)
    {
        if (!empty($xkey) && !empty($xkey->ID)) {
            $xkey = $xkey->ID;
        }
        $this->VarnishManager->purge($xkey);
    }

    private function get_register_events()
    {
        $actions = [
            'publish_future_post',
            'save_post',
            'deleted_post',
            'trashed_post',
            'edit_post',
        ];
        return apply_filters('woody_varnish_events', $actions);
    }
}
