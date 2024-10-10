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
    public $VarnishManager;

    protected static $key = 'woody_lib_varnish';

    protected $status;

    public function initialize(ParameterManager $parameterManager, Container $container)
    {
        define('WOODY_LIB_VARNISH_VERSION', '1.8.8');
        define('WOODY_LIB_VARNISH_ROOT', __FILE__);
        define('WOODY_LIB_VARNISH_DIR_ROOT', dirname(WOODY_LIB_VARNISH_ROOT));

        parent::initialize($parameterManager, $container);
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
        add_action('woody_flush_varnish', [$this, 'flush'], 10);

        // Send headers
        if (!is_admin() && !defined('WP_CLI') && !defined('DOING_CRON')) {
            // Redirect HTTP headers and server-specific overrides
            add_filter('wp_redirect', [$this->VarnishManager, 'send_redirect_headers']);

            // Init Headers xkey
            add_action('init', [$this->VarnishManager, 'send_headers']);
            add_action('wp', [$this->VarnishManager, 'send_post_headers']);
        }

        // Logged in cookie
        add_action('wp_login', [$this->VarnishManager, 'wp_login'], 1_000_000);
        add_action('wp_logout', [$this->VarnishManager, 'wp_logout'], 1_000_000);
        add_action('save_post', [$this, 'flush'], 10);
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
                    $this->VarnishManager->purge();
                }
            }
        }

        // Force Logout si il ne reste que le cookies de varnish
        add_rewrite_rule('woody-logout', 'index.php?woody_logout=true', 'top');
        $this->VarnishManager->force_logout();
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

        // If this is just a revision, don't continue
        if (wp_is_post_revision($xkey)) {
            return;
        }

        $this->VarnishManager->purge($xkey);
    }
}
