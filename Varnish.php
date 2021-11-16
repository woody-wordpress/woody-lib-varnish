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
    protected $xkey = null;

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
        add_action('woody_varnish_flush', [$this, 'flush'], 10, 2);

        // Send headers
        if (!is_admin()) {
            add_action('wp', [$this->VarnishManager, 'sendHeaders'], 1000000);
        }

        // Logged in cookie
        add_action('wp_login', [$this->VarnishManager, 'wp_login'], 1000000);
        add_action('wp_logout', [$this->VarnishManager, 'wp_logout'], 1000000);

        // Register events to purge post
        foreach ($this->get_register_events() as $event) {
            add_action($event, [$this, 'flush'], 10, 2);
        }

        // // Force remove varnish cookie if logout
        // if (!is_user_logged_in() && !empty($_COOKIE[WOODY_VARNISH_CACHING_COOKIE])) {
        //     setcookie(WOODY_VARNISH_CACHING_COOKIE, null, time()-3600*24*100, COOKIEPATH, COOKIE_DOMAIN, false, true);
        //     wp_redirect('/wp/wp-login.php');
        //     exit;
        // }
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
                if (isset($_GET['flush_admin_notice']) && check_admin_referer('varnish')) {
                    $this->flush_admin_notice();
                }
            }
        }
    }

    public function flush_admin_bar_menu($admin_bar)
    {
        $admin_bar->add_menu(array(
            'id'    => 'flush-varnish',
            'title' => 'Flush Varnish',
            'href'  => wp_nonce_url(add_query_arg('flush_admin_notice', 1), 'varnish'),
            'meta'  => array(
                'title' => 'Flush Varnish',
            )
        ));
    }

    public function flush_admin_notice()
    {
        $this->xkey = $this->VarnishManager->purge();
        add_action('admin_notices', [$this, 'flush_message']);
    }

    public function flush_message()
    {
        if (!empty($this->xkey)) {
            echo sprintf('<div id="message" class="updated fade"><p><strong>Varnish is flushed</strong> (%s)</p></div>', $this->xkey);
        }
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
