<?php

/**
 * Woody Library Varnish
 * @author Léo POIROUX
 * @copyright Raccourci Agency 2021
 */

namespace Woody\Lib\Varnish\Services;

class VarnishManager
{
    // ------------------------
    // PURGE METHOD
    // ------------------------
    public function purge($xkey = null)
    {
        if (empty($xkey)) {
            $xkey = WP_SITE_KEY;
        } else {
            $xkey = WP_SITE_KEY . '_' . $xkey;
        }

        $purgeme = 'http://' . WOODY_VARNISH_CACHING_IPS . '/' . $xkey;
        $response = wp_remote_request($purgeme, ['method' => 'PURGE', "sslverify" => false]);
        if ($response instanceof WP_Error) {
            foreach ($response->errors as $error => $errors) {
                $noticeMessage = 'Error ' . $error . ' : ';
                foreach ($errors as $error => $description) {
                    $noticeMessage .= ' - ' . $description;
                }
                output_warning(['woody_flush_varnish' => $noticeMessage, 'purgeme' => $purgeme]);
            }
        } else {
            output_success(sprintf('woody_flush_varnish : %s', $purgeme));
            return $xkey;
        }
    }

    // ------------------------
    // HEADERS
    // ------------------------
    public function sendHeaders()
    {
        global $post;

        // Headers X-VC
        $headers = [
            'X-VC-TTL' => '0',
            'X-VC-Enabled' => 'false',
            'X-VC-Debug' => 'false',
        ];

        if (WOODY_VARNISH_CACHING_ENABLE) {
            $headers['X-VC-Enabled'] = 'true';
            if (is_user_logged_in()) {
                $headers['X-VC-Cacheable'] = 'NO:User is logged in';
            } else {
                $headers['X-VC-TTL'] = $this->getTTL();
            }
            if (WOODY_VARNISH_CACHING_DEBUG) {
                $headers['X-VC-Debug'] = 'true';
            }
        }

        $headers = apply_filters('woody_varnish_override_headers', $headers);
        foreach ($headers as $key => $val) {
            header($key . ': ' . $val, true);
        }

        // xkeys to ban
        $xkeys = [
            WP_SITE_KEY,
            WP_SITE_KEY . '_' . $post->ID
        ];

        $xkeys = apply_filters('woody_varnish_override_xkeys', $xkeys);
        foreach ($xkeys as $val) {
            header('xkey: ' . $val, false);
        }
    }

    private function getTTL()
    {
        $woody_varnish_caching_ttl = apply_filters('woody_varnish_override_ttl', null);
        if (!empty($woody_varnish_caching_ttl)) {
            return $woody_varnish_caching_ttl;
        } else {
            global $post;
            if (!empty($post)) {
                // Using $post->post_password instead of post_password_required() that return false when the password is correct
                // So protected pages where cached with default TTL
                if ($post->post_password) {
                    $woody_varnish_caching_ttl = 0;
                } else {
                    // Force "no format" because otherwise generates a cache or shortcodes are not yet generated
                    $sections = get_field('section', $post->ID, false);
                    if (is_array($sections)) {
                        foreach ($sections as $section) {
                            // field_5b043f0525968 == section_content
                            if (!empty($section['field_5b043f0525968']) && is_array($section['field_5b043f0525968'])) {
                                foreach ($section['field_5b043f0525968'] as $section_content) {
                                    if ($section_content['acf_fc_layout'] == 'tabs_group') {
                                        // field_5b4722e2c1c13_field_5b471f474efee == tabs
                                        if (!empty($section_content['field_5b4722e2c1c13_field_5b471f474efee']) && is_array($section_content['field_5b4722e2c1c13_field_5b471f474efee'])) {
                                            foreach ($section_content['field_5b4722e2c1c13_field_5b471f474efee'] as $tab) {
                                                // field_5b4728182f9b0_field_5b4727a878098_field_5b91294459c24 == light_section_content
                                                if (!empty($tab['field_5b4728182f9b0_field_5b4727a878098_field_5b91294459c24']) && is_array($tab['field_5b4728182f9b0_field_5b4727a878098_field_5b91294459c24'])) {
                                                    foreach ($tab['field_5b4728182f9b0_field_5b4727a878098_field_5b91294459c24'] as $light_section_content) {
                                                        $light_section_content['focused_sort'] = (!empty($light_section_content['field_5b912bde59c2b_field_5b27a67203e48'])) ? $light_section_content['field_5b912bde59c2b_field_5b27a67203e48'] : '';
                                                        $ttl = $this->getTLLbyField($light_section_content);
                                                        if (!empty($ttl)) {
                                                            $woody_varnish_caching_ttl = $ttl;
                                                            break 4;
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    } else {
                                        if ($section_content['acf_fc_layout'] == 'auto_focus' && !(empty($section_content['field_5b27a7859ddeb_field_5b27a67203e48']))) {
                                            $section_content['focused_sort'] = $section_content['field_5b27a7859ddeb_field_5b27a67203e48'];
                                        }
                                        $ttl = $this->getTLLbyField($section_content);
                                        if (!empty($ttl)) {
                                            $woody_varnish_caching_ttl = $ttl;
                                            break 2;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if ($woody_varnish_caching_ttl !== 0 && empty($woody_varnish_caching_ttl)) {
                $woody_varnish_caching_ttl = WOODY_VARNISH_CACHING_TTL;
            }

            return $woody_varnish_caching_ttl;
        }
    }

    private function getTLLbyField($section_content)
    {
        if ($section_content['acf_fc_layout'] == 'auto_focus_sheets' || $section_content['acf_fc_layout'] == 'manual_focus_minisheet') {
            return WOODY_VARNISH_CACHING_TTL_FOCUSSHEET;
        } elseif ($section_content['acf_fc_layout'] == 'auto_focus' || $section_content['acf_fc_layout'] == 'auto_focus_topics') {
            return WOODY_VARNISH_CACHING_TTL_FOCUSRANDOM;
        } elseif ($section_content['acf_fc_layout'] == 'weather') {
            return WOODY_VARNISH_CACHING_TTL_WEATHERPAGE;
        } elseif ($section_content['acf_fc_layout'] == 'infolive') {
            return WOODY_VARNISH_CACHING_TTL_LIVEPAGE;
        }
    }

    // ------------------------
    // LOGIN / LOGOUT
    // ------------------------
    public function wp_login()
    {
        if (!empty(WOODY_VARNISH_CACHING_COOKIE)) {
            setcookie(WOODY_VARNISH_CACHING_COOKIE, 1, time()+3600*24*100, COOKIEPATH, COOKIE_DOMAIN, false, true);
        }
    }

    public function wp_logout()
    {
        rcd('logout', true);
        if (!empty(WOODY_VARNISH_CACHING_COOKIE)) {
            setcookie(WOODY_VARNISH_CACHING_COOKIE, null, time()-3600*24*100, COOKIEPATH, COOKIE_DOMAIN, false, true);
        }
    }

    public function force_logout()
    {
        // // Force remove varnish cookie if logout
        // if (!is_user_logged_in() && !empty($_COOKIE[WOODY_VARNISH_CACHING_COOKIE])) {
        //     global $wp;
        //     $current_url = home_url(add_query_arg($_GET, $wp->request));
        //     setcookie(WOODY_VARNISH_CACHING_COOKIE, null, time()-3600*24*100, COOKIEPATH, COOKIE_DOMAIN, false, true);
        //     wp_redirect(wp_specialchars_decode(wp_logout_url($current_url)));
        //     exit();
        // }
    }
}
