<?php

/**
 * Woody Library Varnish
 * @author Léo POIROUX
 * @copyright Raccourci Agency 2021
 */

namespace Woody\Lib\Varnish\Services;

class VarnishManager
{
    protected $notice;

    // ------------------------
    // PURGE METHOD
    // ------------------------
    public function purge($xkey = null)
    {
        $actions = [];
        $xkey = empty($xkey) ? WP_SITE_KEY : WP_SITE_KEY . '_' . $xkey;
        foreach (WOODY_VARNISH_CACHING_IPS as $woody_varnish_caching_ip) {
            $purgeme = 'http://' . $woody_varnish_caching_ip . '/' . $xkey;
            $response = wp_remote_request($purgeme, ['method' => 'PURGE', "sslverify" => false]);
            if (!is_wp_error($response) && ($response['response']['code'] == 200 || $response['response']['code'] == 201)) {
                output_success(sprintf('woody_flush_varnish : %s', $purgeme));
                $actions[$purgeme] = true;
            } else {
                foreach ($response->errors as $error => $errors) {
                    $message = 'Error ' . $error . ' : ';
                    foreach ($errors as $description) {
                        $message .= ' - ' . $description;
                    }

                    $actions[$purgeme] = false;
                    output_error(['woody_flush_varnish' => $message, 'purgeme' => $purgeme]);
                }
            }
        }

        if (!(defined('WP_CLI')) && !empty($actions) && is_array($actions)) {
            foreach ($actions as $purgeme => $status) {
                $class = ($status) ? 'updated' : 'error';
                $message = ($status) ? 'Varnish is flushed' : 'Varnish not flushed (an error occured)';
                $this->notice = sprintf('<div id="message" class="%s fade"><p><strong>%s</strong> - %s</p></div>', $class, $message, $purgeme);
                add_action('admin_notices', function () { echo $this->notice; });
            }
        }

        // On vide toujours le CDN après avoir vider le Varnish complètement ou partiellement
        do_action('woody_flush_cdn');
    }

    // ------------------------
    // HEADERS
    // ------------------------
    public function send_headers()
    {
        // Headers X-VC
        $headers = [
            'X-VC-TTL' => '0',
            'X-VC-Enabled' => 'false',
            'X-VC-Debug' => 'false',
        ];

        if (WOODY_VARNISH_CACHING_ENABLE) {
            $headers['X-VC-Enabled'] = 'true';
            if (is_user_logged_in() || !empty($_COOKIE[WOODY_VARNISH_CACHING_COOKIE])) {
                $headers['X-VC-Cacheable'] = 'NO:User is logged in';
            } else {
                $headers['X-VC-TTL'] = WOODY_VARNISH_CACHING_TTL;
            }

            if (WOODY_VARNISH_CACHING_DEBUG) {
                $headers['X-VC-Debug'] = 'true';
            }
        }

        $headers = apply_filters('woody_varnish_override_headers', $headers);
        foreach ($headers as $key => $val) {
            header($key . ': ' . $val);
        }

        // xkey for Varnish ban
        header('xkey: ' . WP_SITE_KEY, false);
    }

    public function send_post_headers()
    {
        global $post;
        if (!empty($post->ID) && !empty($post->ID)) {
            header('xkey: ' . WP_SITE_KEY . '_' . $post->ID, false);
            header('X-VC-TTL: ' . $this->getTTL());
        }
    }

    public function send_redirect_headers($url, $status = 302)
    {
        header('X-VC-TTL: 120');
        return $url;
    }

    private function getTTL()
    {
        $woody_varnish_caching_ttl = apply_filters('woody_varnish_override_ttl', null);
        if (!empty($woody_varnish_caching_ttl)) {
            return $woody_varnish_caching_ttl;
        } else {
            global $post;
            $woody_varnish_caching_ttl = WOODY_VARNISH_CACHING_TTL;

            if (!empty($post)) {
                // Using $post->post_password instead of post_password_required() that return false when the password is correct
                // So protected pages where cached with default TTL
                if ($post->post_password) {
                    $woody_varnish_caching_ttl = 0;
                } else {
                    // Force "no format" because otherwise generates a cache or shortcodes are not yet generated
                    $acf_fc_layouts = [];
                    $sections = get_field('section', $post->ID, false);
                    if (is_array($sections)) {
                        foreach ($sections as $section) {
                            // field_5b043f0525968 == section_content
                            if (is_array($section['field_5b043f0525968'])) {
                                foreach ($section['field_5b043f0525968'] as $section_content) {
                                    if ($section_content['acf_fc_layout'] == 'tabs_group') {

                                        // field_5b4722e2c1c13_field_5b471f474efee == tabs
                                        if (is_array($section_content['field_5b4722e2c1c13_field_5b471f474efee'])) {
                                            foreach ($section_content['field_5b4722e2c1c13_field_5b471f474efee'] as $tab) {

                                                // field_5b4728182f9b0_field_5b4727a878098_field_5b91294459c24 == light_section_content
                                                if (is_array($tab['field_5b4728182f9b0_field_5b4727a878098_field_5b91294459c24'])) {
                                                    foreach ($tab['field_5b4728182f9b0_field_5b4727a878098_field_5b91294459c24'] as $light_section_content) {
                                                        $acf_fc_layouts[] = $this->isRandom($light_section_content);
                                                    }
                                                }
                                            }
                                        }
                                    } else {
                                        $acf_fc_layouts[] = $this->isRandom($section_content);
                                    }
                                }
                            }
                        }
                    }

                    $acf_fc_layouts = array_unique($acf_fc_layouts);
                    foreach ($acf_fc_layouts as $acf_fc_layout) {
                        $layout_ttl = $this->getTLLbyLayout($acf_fc_layout);
                        if ($layout_ttl < $woody_varnish_caching_ttl) {
                            $woody_varnish_caching_ttl = $layout_ttl;
                        }
                    }
                }
            }

            return $woody_varnish_caching_ttl;
        }
    }

    private function isRandom($section_content)
    {
        $return = $section_content['acf_fc_layout'];
        foreach ($section_content as $field => $value) {
            if (strpos($field, 'field_5b27a67203e48') !== false && $value == 'random' || strpos($field, 'field_64df5e9a12a3c') !== false && $value == 'random' || $return == 'youbook_focus') {
                $return .= '_random';
                break;
            }
        }

        return $return;
    }

    private function getTLLbyLayout($acf_fc_layout)
    {
        if ($acf_fc_layout == 'auto_focus_sheets' || $acf_fc_layout == 'manual_focus_minisheet') {
            return WOODY_VARNISH_CACHING_TTL_FOCUSSHEET;
        } elseif ($acf_fc_layout == 'auto_focus' || $acf_fc_layout == 'auto_focus_topics' || $acf_fc_layout == 'youbook_focus') {
            return WOODY_VARNISH_CACHING_TTL_FOCUS;
        } elseif ($acf_fc_layout == 'auto_focus_random' || $acf_fc_layout == 'auto_focus_topics_random' || $acf_fc_layout == 'profile_focus_random' || $acf_fc_layout == 'youbook_focus_random') {
            return WOODY_VARNISH_CACHING_TTL_FOCUSRANDOM;
        } elseif ($acf_fc_layout == 'weather') {
            return WOODY_VARNISH_CACHING_TTL_WEATHERPAGE;
        } elseif ($acf_fc_layout == 'infolive') {
            return WOODY_VARNISH_CACHING_TTL_LIVEPAGE;
        } else {
            return WOODY_VARNISH_CACHING_TTL;
        }
    }

    // ------------------------
    // LOGIN / LOGOUT
    // ------------------------
    public function wp_login()
    {
        if (!empty(WOODY_VARNISH_CACHING_COOKIE)) {
            setcookie(WOODY_VARNISH_CACHING_COOKIE, 1, ['expires' => time() + 3600 * 24 * 100, 'path' => COOKIEPATH, 'domain' => COOKIE_DOMAIN, 'secure' => false, 'httponly' => true]);
        }
    }

    public function wp_logout()
    {
        if (!empty(WOODY_VARNISH_CACHING_COOKIE)) {
            setcookie(WOODY_VARNISH_CACHING_COOKIE, null, ['expires' => time() - 3600 * 24 * 100, 'path' => COOKIEPATH, 'domain' => COOKIE_DOMAIN, 'secure' => false, 'httponly' => true]);
        }
    }

    public function woody_logout()
    {
        header('X-VC-TTL: 0');
        do_action('wp_logout');
        if (!empty($_GET['redirect_to'])) {
            wp_safe_redirect(trim(strip_tags($_GET['redirect_to'])), 302, 'Woody Varnish Logout');
            exit();
        } else {
            wp_safe_redirect(home_url(), 302, 'Woody Varnish Logout');
            exit();
        }
    }

    public function force_logout()
    {
        if (strpos($_SERVER['REQUEST_URI'], 'woody-logout') !== false) {
            // Do nothing
        } elseif (!is_user_logged_in() && !empty($_COOKIE[WOODY_VARNISH_CACHING_COOKIE])) {
            // Force remove varnish cookie if logout
            $current_url = home_url(add_query_arg($_GET, $_SERVER['REQUEST_URI']));
            wp_safe_redirect('/woody-logout?redirect_to='.$current_url, 302, 'Woody Varnish Logout');
            exit();
        }
    }
}
