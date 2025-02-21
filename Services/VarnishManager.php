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
        if (strpos($xkey, 'multisite_') === false) {
            $xkey = empty($xkey) ? WP_SITE_KEY : WP_SITE_KEY . '_' . $xkey;
        }

        if(!empty(WOODY_VARNISH_CACHING_IPS) && is_array(WOODY_VARNISH_CACHING_IPS)) {

            // Add Flush CDN if Cloudflare Protection is enabled
            $woody_varnish_caching_ips = WOODY_VARNISH_CACHING_IPS;
            if((in_array('cloudflare_protection', WOODY_OPTIONS_ERP) || in_array('cloudflare_protection', WOODY_OPTIONS)) && !in_array('flushcdn.infra.raccourci.fr:80', $woody_varnish_caching_ips)) {
                $woody_varnish_caching_ips[] = 'flushcdn.infra.raccourci.fr:80';
            }

            foreach ($woody_varnish_caching_ips as $woody_varnish_caching_ip) {
                $purge_url = 'http://' . $woody_varnish_caching_ip . '/' . $xkey;
                $response = wp_remote_request($purge_url, ['method' => 'PURGE', 'sslverify' => false]);
                if (!is_wp_error($response) && ($response['response']['code'] == 200 || $response['response']['code'] == 201)) {
                    output_success(sprintf('woody_flush_varnish : %s', $purge_url));
                    $actions[$purge_url] = true;
                } elseif(!empty($response->errors)) {
                    foreach ($response->errors as $error => $errors) {
                        $message = 'Error ' . $error . ' : ';
                        foreach ($errors as $description) {
                            $message .= ' - ' . $description;
                        }

                        $actions[$purge_url] = false;
                        output_warning(['woody_flush_varnish' => $message, 'purge_url' => $purge_url]);
                    }
                } elseif($response['response']['code'] != 200 && $response['response']['code'] != 201) {
                    $message = 'Error ' . $response['response']['code'] . ' : ' . $response['response']['message'];
                    $actions[$purge_url] = false;
                    output_warning(['woody_flush_varnish' => $message, 'purge_url' => $purge_url]);
                }
            }

            if (!(defined('WP_CLI')) && !empty($actions) && is_array($actions)) {
                foreach ($actions as $purge_url => $status) {
                    $class = ($status) ? 'updated' : 'error';
                    $message = ($status) ? 'Varnish is flushed' : 'Varnish not flushed (an error occured)';
                    $this->notice .= sprintf('<div id="message" class="%s fade"><p><strong>%s</strong> - %s</p></div>', $class, $message, $purge_url);
                }
                // TODO: Revoir cette notice qui apparait pour les clients à des moments bizarres
                //add_action('admin_notices', function () { echo $this->notice; });
            }
        } else {
            output_error(['WOODY_VARNISH_CACHING_IPS is empty or not array' => WOODY_VARNISH_CACHING_IPS]);
        }
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
        $woody_varnish_caching_ttl = WOODY_VARNISH_CACHING_TTL;

        global $post;
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

    private function isRandom($section_content)
    {
        $return = $section_content['acf_fc_layout'];

        foreach ($section_content as $field => $value) {
            if ($section_content['acf_fc_layout'] == 'catalog_focus') {
                if(is_array($value) && !empty($value) && $field == 'field_669697ce6670c_field_669769b831caa') {
                    foreach ($value as $fields) {
                        if(!empty($fields['field_66977eb92b7df'])) {
                            foreach ($fields['field_66977eb92b7df'] as $subfield => $subvalue) {
                                if(!empty($subfield) && $subfield == 'field_669783f6b5d13' && !empty($subvalue['field_66978461e3beb'])) {
                                    if($subvalue['field_66978461e3beb'] == 'random') {
                                        $return .= '_random';
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                if (strpos($field, 'field_5b27a67203e48') !== false && $value == 'random' || strpos($field, 'field_64df5e9a12a3c') !== false && $value == 'random' || strpos($field, 'field_6661b3e125d63') !== false && $value == 'random' || $return == 'youbook_focus') {
                    $return .= '_random';
                    break;
                }
            }
        }

        return $return;
    }

    private function getTLLbyLayout($acf_fc_layout)
    {
        if ($acf_fc_layout == 'auto_focus_sheets' || $acf_fc_layout == 'manual_focus_minisheet' || $acf_fc_layout == 'event_block') {
            return WOODY_VARNISH_CACHING_TTL_FOCUSSHEET;
        } elseif ($acf_fc_layout == 'auto_focus' || $acf_fc_layout == 'auto_focus_topics' || $acf_fc_layout == 'youbook_focus' || $acf_fc_layout == 'auto_focus_leaflets' || $acf_fc_layout == 'catalog_focus') {
            return WOODY_VARNISH_CACHING_TTL_FOCUS;
        } elseif ($acf_fc_layout == 'auto_focus_random' || $acf_fc_layout == 'auto_focus_topics_random' || $acf_fc_layout == 'profile_focus_random' || $acf_fc_layout == 'youbook_focus_random' || $acf_fc_layout == 'auto_focus_leaflets_random' || $acf_fc_layout == 'catalog_focus_random') {
            return WOODY_VARNISH_CACHING_TTL_FOCUSRANDOM;
        } elseif ($acf_fc_layout == 'weather') {
            return WOODY_VARNISH_CACHING_TTL_WEATHERPAGE;
        } elseif ($acf_fc_layout == 'content_list') {
            return WOODY_VARNISH_CACHING_TTL_CONTENTLIST;
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
