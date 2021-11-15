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
    protected $refresh_list = [];

    public function initialize(ParameterManager $parameters, Container $container)
    {
        define('WOODY_LIB_VARNISH_VERSION', '1.0.0');
        define('WOODY_LIB_VARNISH_ROOT', __FILE__);
        define('WOODY_LIB_VARNISH_DIR_ROOT', dirname(WOODY_LIB_VARNISH_ROOT));

        parent::initialize($parameters, $container);
        $this->dropZoneManager = $this->container->get('varnish.manager');
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
        add_action('woody_varnish_flush', [$this, 'flush'], 10);
    }

    // ------------------------
    // GETTER / SETTER
    // ------------------------

    public function flush()
    {
        $this->dropZoneManager->flush();
    }

    // ------------------------
    // ADMIN BAR
    // ------------------------
    public function init()
    {
        if (is_admin()) {
            $user = wp_get_current_user();
            if (in_array('administrator', $user->roles)) {
                add_action('admin_bar_menu', [$this, 'warm_all_adminbar'], 100);
                if (isset($_GET['refresh_varnish']) && check_admin_referer('varnish')) {
                    $this->refresh_varnish();
                }
            }
        }
    }

    public function warm_all_adminbar($admin_bar)
    {
        $admin_bar->add_menu(array(
            'id'    => 'warm-all-varnish',
            'title' => 'Refresh Varnish',
            'href'  => wp_nonce_url(add_query_arg('refresh_varnish', 1), 'varnish'),
            'meta'  => array(
                'title' => 'Refresh Varnish',
            )
        ));
    }

    public function refresh_varnish()
    {
        $this->refresh_list = $this->warm_all();
        add_action('admin_notices', [$this, 'refresh_message']);
    }

    public function refresh_message()
    {
        if (!empty($this->refresh_list)) {
            echo '<div id="message" class="updated fade"><p><strong>Varnish is refreshed</strong>';
            foreach ($this->refresh_list as $item) {
                echo '<br />&nbsp;•&nbsp;' . $item;
            }
            echo '</p></div>';
        } else {
            echo '<div id="message" class="error fade"><p><strong>Varnish is empty</strong></p></div>';
        }
    }
}
