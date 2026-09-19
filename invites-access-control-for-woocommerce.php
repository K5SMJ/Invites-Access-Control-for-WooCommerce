<?php
/**
 * Plugin Name: Invites & Access Control for WooCommerce
 * Description: Invite-only WooCommerce access system (token gating) with admin UI. Includes an on/off switch to open the shop to everyone while keeping invite history.
 * Version: 1.0.2
 * Author: K5SMJ
 * License: GPL-3.0-only
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Master option:
 *  - 1 = invites ON (invite-only shopping)
 *  - 0 = invites OFF (shop open to everyone; invite history preserved; generator disabled)
 */
function inviacco_invites_enabled(): bool {
    return (bool) get_option('inviacco_invites_enabled', 1);
}

function inviacco_set_invites_enabled( bool $enabled ): void {
    update_option('inviacco_invites_enabled', $enabled ? 1 : 0);
}

/**
 * If the system is OFF, ensure any old scheduled cleanup hook is cleared.
 */
function inviacco_invites_maybe_disable_cron(): void {
    if ( ! inviacco_invites_enabled() ) {
        wp_clear_scheduled_hook('inviacco_invite_cleanup_daily');
    }
}
add_action('init', 'inviacco_invites_maybe_disable_cron', 1);


/**
 * Purchase gating:
 * - If invites are OFF, leave WooCommerce behavior unchanged (shop open to all).
 * - If invites are ON, require a valid invite window.
 */
add_filter( 'woocommerce_is_purchasable', function( $purchasable ) {
    if ( ! inviacco_invites_enabled() ) return $purchasable;
    return inviacco_user_can_shop();
}, 20 );

add_filter( 'woocommerce_variation_is_purchasable', function( $purchasable ) {
    if ( ! inviacco_invites_enabled() ) return $purchasable;
    return inviacco_user_can_shop();
}, 20 );


/**
 * read more button fix
 */
add_filter( 'woocommerce_product_add_to_cart_text', 'inviacco_woocommerce_read_more_text', 10, 2 );
function inviacco_woocommerce_read_more_text( $text, $product ) {
    // Check if the product is not purchasable (i.e., Read more button appears)
    if ( ! $product->is_purchasable() ) {
        $text = 'product details'; // Replace with whatever you want
    }
    return $text;
}

/**
 * Invite Token Generator
 */
/**
 * your store – Invite-only WooCommerce (Compact Collapsible Admin + Editable Email)
 */

/* =========================================================
 * 0) HELPERS
 * ========================================================= */

function inviacco_user_can_shop() {
    
    if ( ! inviacco_invites_enabled() ) return true;
if ( current_user_can('administrator') ) return true;

    $user_id = get_current_user_id();
    if ( ! $user_id ) return false;

    // Use roles (more reliable than current_user_can('inviacco_invited_customer'))
    $user  = get_userdata($user_id);
    $roles = ( $user && ! empty($user->roles) ) ? (array) $user->roles : [];

    // Must have role
    if ( ! in_array('inviacco_invited_customer', $roles, true) ) return false;

    // Must still be within the stored access window
    $expires = intval( get_user_meta($user_id, '_inviacco_access_expires', true) );

    // If no expiry recorded, treat as expired (matches "timed window" requirement)
    if ( $expires <= 0 ) return false;

    return time() <= $expires;
}


function inviacco_invite_option_key( $token ) {
    return 'inviacco_invite_' . $token;
}

function inviacco_get_invite_link( $token ) {
    return add_query_arg( 'invite', $token, wc_get_page_permalink('myaccount') );
}

function inviacco_is_expired( $invite ) {
    return ( is_array($invite) && !empty($invite['expires']) && time() > intval($invite['expires']) );
}

function inviacco_token_short( $token, $len = 8 ) {
    $token = (string) $token;
    return strlen($token) <= $len ? $token : substr($token, 0, $len) . '…';
}

/* =========================================================
 * 1) ROLE + SHOP ACCESS
 * ========================================================= */

add_action('init', function() {
    if ( ! get_role('inviacco_invited_customer') ) {
        add_role('inviacco_invited_customer', 'Invited Customer', ['read' => true]);
    }
});

/* =========================================================
 * 2) INVITE STORAGE (UNLIMITED) + EXP DAYS
 * ========================================================= */

function inviacco_create_invite( $email, $days = 7 ) { // default now 7
    $days  = max( 1, min( 365, intval( $days ) ) );
    $token = wp_generate_password( 24, false );

    $data = [
        'email'    => $email,
        'created'  => time(),
        'exp_days' => $days,
        'expires'  => strtotime('+' . $days . ' days'),
        'used'     => false,
    ];

    add_option( inviacco_invite_option_key($token), $data );

    return $token;
}

function inviacco_load_all_invites() {
    $invites = [];

    foreach ( wp_load_alloptions() as $key => $value ) {
        if ( strpos($key, 'inviacco_invite_') === 0 ) {
            $token = str_replace('inviacco_invite_', '', $key);
            $data  = maybe_unserialize($value);

            if ( ! is_array($data) ) $data = [];
            if ( empty($data['created']) )  $data['created']  = 0;
            if ( empty($data['expires']) )  $data['expires']  = 0;
            if ( empty($data['exp_days']) ) $data['exp_days'] = 7;   // default now 7
            if ( ! isset($data['used']) )   $data['used']     = false;
            if ( empty($data['email']) )    $data['email']    = '';

            $invites[] = [
                'token' => $token,
                'data'  => $data,
            ];
        }
    }

    usort($invites, function($a, $b) {
        return intval($b['data']['created']) <=> intval($a['data']['created']);
    });

    return $invites;
}


/* =========================================================
 * 3) INVITE FLOW (REDIRECT + BANNER + TOKEN PERSIST + APPLY ROLE + STORE ACCESS EXPIRY)
 *    Timed purchase window:
 *    - On successful register OR login (or already-logged-in) with a valid invite:
 *      - add inviacco_invited_customer role
 *      - store user meta _inviacco_access_expires = invite['expires']
 *      - mark invite used (single-use)
 * ========================================================= */

/**
 * If someone hits any page with ?invite=TOKEN while logged OUT,
 * redirect them to My Account so they can login/register.
 */
add_action('template_redirect', function() {
    
    if ( ! inviacco_invites_enabled() ) return;
if ( is_user_logged_in() ) return;
    if ( empty($_GET['invite']) ) return;
    if ( is_account_page() ) return;

    $token = sanitize_text_field( wp_unslash($_GET['invite']) );
    wp_safe_redirect( inviacco_get_invite_link( $token ) );
    exit;
});

/**
 * Show banner on My Account login/register screen when invite is present.
 */
add_action('woocommerce_before_customer_login_form', function() {
    
    if ( ! inviacco_invites_enabled() ) return;
if ( empty($_GET['invite']) ) return;

    $token  = sanitize_text_field( wp_unslash($_GET['invite']) );
    $invite = get_option( inviacco_invite_option_key($token) );

    // If token doesn't exist, say nothing (prevents info-leaks)
    if ( ! is_array($invite) ) return;

    // Expired? Tell them explicitly.
    if ( ! empty($invite['expires']) && time() > intval($invite['expires']) ) {
        wc_print_notice(
            '⏰ <strong>This invite has expired.</strong> Please request a new invite link.',
            'error'
        );
        return;
    }

    // Valid invite arriving at login/register page
    if ( ! is_user_logged_in() ) {
        wc_print_notice(
            '🎉 <strong>You’re invited.</strong> Create an account or log in to access the your store shop.',
            'notice'
        );
    }
});


/* =========================================================
 * 3B) INVITE TOKEN PERSISTENCE (SESSION + COOKIE FALLBACK)
 *    - Stores invite token from ?invite=TOKEN
 *    - Uses Woo session when available
 *    - Adds cookie fallback so token survives:
 *        • login / register redirects
 *        • browser differences
 *        • WC session timing gaps
 * ========================================================= */

/**
 * Capture invite token when user lands with ?invite=...
 * Store in WC session (if available) AND cookie fallback.
 */
function inviacco_capture_invite_token() {
    
    if ( ! inviacco_invites_enabled() ) { return; }
if ( empty($_GET['invite']) ) return;

    $token = sanitize_text_field( wp_unslash($_GET['invite']) );
    if ( ! $token ) return;

    $invite = get_option( inviacco_invite_option_key($token) );
    if ( ! is_array($invite) ) return;

    // Do not persist expired tokens
    if ( ! empty($invite['expires']) && time() > intval($invite['expires']) ) return;

    // WC session (best case)
    if ( function_exists('WC') && WC() && WC()->session ) {
        WC()->session->set('inviacco_invite_token', $token);
    }

    // Cookie fallback (3 hours)
    setcookie(
        'inviacco_invite_token',
        $token,
        time() + 3 * HOUR_IN_SECONDS,
        COOKIEPATH ?: '/',
        COOKIE_DOMAIN,
        is_ssl(),
        true
    );

    // Make immediately available in this request
    $_COOKIE['inviacco_invite_token'] = $token;
}
add_action('init', 'inviacco_capture_invite_token', 20);

/**
 * Get pending invite token:
 * URL → WC session → cookie
 */
function inviacco_get_pending_invite_token() {

    if ( ! empty($_GET['invite']) ) {
        return sanitize_text_field( wp_unslash($_GET['invite']) );
    }

    if ( function_exists('WC') && WC() && WC()->session ) {
        $t = WC()->session->get('inviacco_invite_token');
        if ( ! empty($t) ) return sanitize_text_field((string) $t);
    }

    if ( ! empty($_COOKIE['inviacco_invite_token']) ) {
        return sanitize_text_field(wp_unslash((string) $_COOKIE['inviacco_invite_token']));
    }

    return '';
}

/**
 * Clear stored invite token after successful apply
 * (prevents sticky or reused tokens)
 */
function inviacco_clear_pending_invite_token() {

    if ( function_exists('WC') && WC() && WC()->session ) {
        WC()->session->__unset('inviacco_invite_token');
    }

    setcookie(
        'inviacco_invite_token',
        '',
        time() - 3600,
        COOKIEPATH ?: '/',
        COOKIE_DOMAIN,
        is_ssl(),
        true
    );

    unset($_COOKIE['inviacco_invite_token']);
}

/* =========================================================
 * 3C) APPLY INVITE ON REGISTER / LOGIN (AUTO-REDEEM)
 * ========================================================= */

/**
 * Apply an invite token to a user:
 * - requires valid token that exists + not expired
 * - if unused: mark used + store redeemed_at + store redeemed_user_id
 * - always: add inviacco_invited_customer role (without wiping other roles)
 * - store user meta _inviacco_access_expires = invite['expires']
 * - clear pending token (session/cookie)
 */
function inviacco_apply_invite_to_user( $user_id, $token ) {

    
    if ( ! inviacco_invites_enabled() ) { return false; }
$user_id = intval($user_id);
    $token   = sanitize_text_field( (string) $token );

    if ( $user_id <= 0 || ! $token ) return false;

    $invite = get_option( inviacco_invite_option_key($token) );
    if ( ! is_array($invite) ) return false;

    // Expired? Stop.
    if ( ! empty($invite['expires']) && time() > intval($invite['expires']) ) return false;

    $expires_ts = intval($invite['expires'] ?? 0);
    if ( $expires_ts <= 0 ) return false;

    $user = get_userdata($user_id);
    if ( ! $user || empty($user->user_email) ) return false;

    $invite_email = strtolower(trim($invite['email'] ?? ''));
    $user_email   = strtolower(trim($user->user_email));

    // If the invite has an email, require it to match the account email.
    if ( $invite_email && $invite_email !== $user_email ) {
        return false;
    }

    // If token is already used, only allow the SAME user to re-apply (rehydrate).
    if ( ! empty($invite['used']) ) {
        $rid = intval($invite['redeemed_user_id'] ?? 0);

        // If we recorded a redeemer, enforce same user.
        if ( $rid > 0 && $rid !== $user_id ) {
            return false;
        }

        // If we didn't record a redeemer (older invites / manually marked used),
        // allow rehydrate only if email matches (already enforced above).
    }

    // Add role without wiping other roles
    $wp_user = new WP_User($user_id);
    if ( $wp_user && ! in_array('inviacco_invited_customer', (array) $wp_user->roles, true) ) {
        $wp_user->add_role('inviacco_invited_customer');
    }

    // Store access window tied to invite expiry
    update_user_meta($user_id, '_inviacco_access_expires', $expires_ts);

    // Mark used if not already used
    if ( empty($invite['used']) ) {
        $invite['used']             = true;
        $invite['redeemed_at']      = time();
        $invite['redeemed_user_id'] = $user_id;
        update_option( inviacco_invite_option_key($token), $invite );
    }

    // Clear the token so it doesn't stick around
    inviacco_clear_pending_invite_token();

    return true;
}

/**
 * Try to apply pending invite to a user once they are logged in.
 * Works for:
 * - new registrations (after user created)
 * - existing user logins
 * - already-logged-in users landing with ?invite=...
 */
function inviacco_maybe_apply_invite_for_current_user( $user_id = 0 ) {

    $uid = $user_id ? intval($user_id) : get_current_user_id();
    if ( ! $uid ) return;

    $token = inviacco_get_pending_invite_token();
    if ( ! $token ) return;

    inviacco_apply_invite_to_user( $uid, $token );
}

// After registration
add_action('user_register', function($user_id){
    inviacco_maybe_apply_invite_for_current_user( $user_id );
}, 20);

// After login (fires on wp_signon)
add_action('wp_login', function($user_login, $user){
    if ( $user && ! empty($user->ID) ) {
        inviacco_maybe_apply_invite_for_current_user( $user->ID );
    }
}, 20, 2);

// Already logged in + hits invite link
add_action('wp_loaded', function(){
    if ( is_user_logged_in() && ! empty($_GET['invite']) ) {
        inviacco_maybe_apply_invite_for_current_user( get_current_user_id() );
    }
}, 20);

/* =========================================================
 * 4) HTML EMAIL (UNCHANGED LOOK) – DYNAMIC DAYS + STOCK NOTE
 * ========================================================= */

function inviacco_get_brand_settings() {
    $defaults = [
        'site_name'  => get_bloginfo('name'),
        'site_url'   => home_url('/'),
        'from_name'  => get_bloginfo('name'),
        'from_email' => get_option('admin_email'),
        'logo_url'   => '',
    ];
    $saved = get_option('inviacco_brand_settings', []);
    return wp_parse_args(is_array($saved) ? $saved : [], $defaults);
}

function inviacco_get_logo_url() {
    $settings = inviacco_get_brand_settings();
    return ! empty($settings['logo_url']) ? esc_url($settings['logo_url']) : '';
}

function inviacco_build_invite_email_html( $token ) {

    $invite_link = inviacco_get_invite_link( $token );
    $logo_url    = inviacco_get_logo_url();

    $invite = get_option( inviacco_invite_option_key( $token ) );
    $days   = ( is_array($invite) && ! empty($invite['exp_days']) ) ? intval($invite['exp_days']) : 7;

    $logo_html = '';
    if ( ! empty($logo_url) ) {
        $logo_html = '<img src="'.esc_url($logo_url).'" alt="your store" style="max-width:140px;height:auto;display:block;margin:0 0 22px 0;">';
    }

    return '
<div style="background:#f4f4f4;padding:24px 0;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;">
  <div style="max-width:640px;margin:0 auto;background:#ffffff;padding:24px;border-radius:8px;">
    '.$logo_html.'
    <h2 style="margin:0 0 14px 0;font-size:20px;line-height:1.25;font-weight:600;color:#111;">
      You’re invited to access your store
    </h2>
    <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#111;">
      Howdy,<br><br>
      You’ve been invited to access your store using this unique token.
    </p>

    <div style="margin:22px 0 18px 0;">
      <a href="'.esc_url($invite_link).'" style="background:#111;color:#fff;text-decoration:none;padding:12px 18px;border-radius:6px;display:inline-block;font-weight:600;">
        Accept Your Invite
      </a>
    </div>

    <p style="margin:0 0 20px 0;font-size:14px;line-height:1.6;color:#222;">
      This token is directly tied to your invite, can only be used once, and will expire in '.esc_html($days).' days.
    </p>

    <p style="margin:0 0 22px 0;padding:12px 14px;background:#fafafa;border-left:3px solid #ddd;font-size:13px;line-height:1.6;color:#444;">
      <strong>Note:</strong> Inventory is limited. While your invite is valid for '.esc_html($days).' days,
      access may close earlier if I sell out. If your invite link stops working before the expiration date,
      it probably means I&#39;m out of stock.
    </p>

    <p style="margin:26px 0 0 0;font-size:14px;line-height:1.5;color:#111;">
      — '.esc_html(inviacco_get_brand_settings()['site_name']).'<br>
      <a href="'.esc_url(inviacco_get_brand_settings()['site_url']).'" style="color:#555;text-decoration:none;">'.esc_html(inviacco_get_brand_settings()['site_url']).'</a>
    </p>

    <p style="margin:10px 0 0 0;font-size:11px;line-height:1.4;color:#999;">
      invite token: '.esc_html($token).'
    </p>
  </div>
</div>';
}

/* Ensure HTML email + stable From headers (PHP-safe, no arrow functions) */
add_filter( 'wp_mail_content_type', 'inviacco_mail_content_type' );
function inviacco_mail_content_type( $content_type ) {
    return 'text/html; charset=UTF-8';
}

add_filter( 'wp_mail_from_name', 'inviacco_mail_from_name' );
function inviacco_mail_from_name( $name ) {
    return inviacco_get_brand_settings()['from_name'];
}

add_filter( 'wp_mail_from', 'inviacco_mail_from' );
function inviacco_mail_from( $email ) {
    return inviacco_get_brand_settings()['from_email'];
}


/* =========================================================
 * 5) EMAIL FAILURE DEBUG
 * ========================================================= */

add_action('wp_mail_failed', function( $wp_error ) {
    set_transient('inviacco_last_mail_error', $wp_error->get_error_message(), 10 * MINUTE_IN_SECONDS);
});

/* =========================================================
 * 6) ADMIN UI (COLLAPSIBLE COMPACT LIST + EDITABLE EMAIL)
 *    + Token lifecycle pills: ISSUED / REDEEMED / EXPIRED
 *    + Purchase overlay (USED only): Purchased ✓ + row tint
 *    + Filter bar (All / Issued / Redeemed / Expired)
 * ========================================================= */


/* =========================================================
 * 6) ADMIN MENU + PAGE WRAPPER
 * ========================================================= */

add_action('admin_menu', function() {
    add_submenu_page(
        'woocommerce',
        'Invites & Access Control',
        'Invites & Access Control',
        'manage_options',
        'inviacco-invites',
        'inviacco_invite_admin_page'
    );
});
/**
 * Load the invite admin page assets only on this plugin's WooCommerce submenu page.
 */
function inviacco_enqueue_admin_assets( $hook_suffix ) {
    if ( 'woocommerce_page_inviacco-invites' !== $hook_suffix ) {
        return;
    }

    wp_enqueue_style(
        'inviacco-admin',
        plugins_url( 'assets/admin.css', __FILE__ ),
        array(),
        '1.0.2'
    );

    wp_enqueue_script(
        'inviacco-admin',
        plugins_url( 'assets/admin.js', __FILE__ ),
        array(),
        '1.0.2',
        true
    );
}
add_action( 'admin_enqueue_scripts', 'inviacco_enqueue_admin_assets' );

/* =========================================================
 * 7) ACTION HANDLERS (CREATE / MARK USED / TOGGLE PURCHASED / FORCE EXPIRE / DELETE / SEND)
 * ========================================================= */

function inviacco_handle_invite_actions() {
    if ( isset($_POST['inviacco_save_brand_settings']) ) {
        check_admin_referer('inviacco_brand_settings');
        $settings = [
            'site_name'  => sanitize_text_field(wp_unslash($_POST['inviacco_brand_site_name'] ?? '')),
            'site_url'   => esc_url_raw(wp_unslash($_POST['inviacco_brand_site_url'] ?? '')),
            'from_name'  => sanitize_text_field(wp_unslash($_POST['inviacco_brand_from_name'] ?? '')),
            'from_email' => sanitize_email(wp_unslash($_POST['inviacco_brand_from_email'] ?? '')),
            'logo_url'   => esc_url_raw(wp_unslash($_POST['inviacco_brand_logo_url'] ?? '')),
        ];
        if ( ! $settings['site_name'] ) $settings['site_name'] = get_bloginfo('name');
        if ( ! $settings['site_url'] ) $settings['site_url'] = home_url('/');
        if ( ! is_email($settings['from_email']) ) $settings['from_email'] = get_option('admin_email');
        if ( ! $settings['from_name'] ) $settings['from_name'] = get_bloginfo('name');
        update_option('inviacco_brand_settings', $settings);
        echo '<div class="notice notice-success"><p>Brand and email settings saved.</p></div>';
    }


    

    // TOGGLE SYSTEM (ON/OFF)
    if ( isset($_POST['inviacco_toggle_invites']) && check_admin_referer('inviacco_invites_toggle') ) {
        inviacco_set_invites_enabled( ! inviacco_invites_enabled() );
        inviacco_invites_maybe_disable_cron();
        echo '<div class="notice notice-success"><p>Invite system is now <strong>' . ( inviacco_invites_enabled() ? 'ON' : 'OFF' ) . '</strong>.</p></div>';
    }

// CREATE INVITE
    if ( isset($_POST['inviacco_create_invite']) && check_admin_referer('inviacco_invite_create') ) {
        
        if ( ! inviacco_invites_enabled() ) {
            echo '<div class=\"notice notice-warning\"><p>Invite system is currently <strong>OFF</strong>. New invites are disabled. Turn the system ON to create new invites.</p></div>';
            return;
        }
$email = sanitize_email(wp_unslash($_POST['inviacco_invite_email'] ?? ''));
        $days  = max(1, min(365, intval(wp_unslash($_POST['inviacco_exp_days'] ?? 7))));

        if ( ! is_email($email) ) {
            echo '<div class="notice notice-error"><p>Email is required (and must be valid).</p></div>';
        } else {
            inviacco_create_invite($email, $days);
            echo '<div class="notice notice-success"><p>Invite created for <strong>'.esc_html($email).'</strong> ('.esc_html($days).' days).</p></div>';
        }
    }

    // FORCE-EXPIRE (admin-only) — only meaningful for ISSUED; UI hides it for redeemed/expired
    if ( isset($_GET['inviacco_mark_expired']) && check_admin_referer('inviacco_invite_actions') ) {
        $token  = sanitize_text_field( wp_unslash($_GET['inviacco_mark_expired']) );
        $invite = get_option( inviacco_invite_option_key($token) );

        if ( is_array($invite) ) {
            // Force expiration into the past so your existing status logic shows EXPIRED
            $invite['expires'] = time() - 60;

            update_option( inviacco_invite_option_key($token), $invite );

            echo '<div class="notice notice-success"><p>Invite marked as expired.</p></div>';
        }
    }

	// MARK USED (REDEEMED)
	if ( isset($_GET['inviacco_mark_used']) && check_admin_referer('inviacco_invite_actions') ) {
		$token  = sanitize_text_field( wp_unslash($_GET['inviacco_mark_used']) );
		$invite = get_option( inviacco_invite_option_key($token) );

		if ( is_array($invite) ) {
			$invite['used'] = true;
			if ( empty($invite['redeemed_at']) ) $invite['redeemed_at'] = time();
			update_option( inviacco_invite_option_key($token), $invite );
			echo '<div class="notice notice-success"><p>Invite marked as redeemed.</p></div>';
		}
	}


    // TOGGLE PURCHASED (overlay; meaningful on REDEEMED only)
    if ( isset($_GET['inviacco_toggle_purchased']) && check_admin_referer('inviacco_invite_actions') ) {
        $token  = sanitize_text_field( wp_unslash($_GET['inviacco_toggle_purchased']) );
        $invite = get_option( inviacco_invite_option_key($token) );

        if ( is_array($invite) ) {
            $invite['purchased'] = empty($invite['purchased']) ? true : false;
            update_option( inviacco_invite_option_key($token), $invite );
            echo '<div class="notice notice-success"><p>Purchase flag updated.</p></div>';
        }
    }

    // DELETE
    if ( isset($_GET['inviacco_delete']) && check_admin_referer('inviacco_invite_actions') ) {
        $token = sanitize_text_field( wp_unslash($_GET['inviacco_delete']) );
        delete_option( inviacco_invite_option_key($token) );
        echo '<div class="notice notice-success"><p>Invite deleted.</p></div>';
    }

    // SEND / RESEND (editable subject/body)
    if ( isset($_POST['inviacco_send_invite']) && check_admin_referer('inviacco_invite_send') ) {
        $to      = sanitize_email(wp_unslash($_POST['inviacco_to'] ?? ''));
        $subject = sanitize_text_field(wp_unslash($_POST['inviacco_subject'] ?? ''));
        $body    = wp_kses_post(wp_unslash($_POST['inviacco_body'] ?? ''));

        if ( ! is_email($to) ) {
            echo '<div class="notice notice-error"><p>Recipient email is invalid.</p></div>';
        } else {
            $sent = wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
            echo $sent
                ? '<div class="notice notice-success"><p>Email sent to <strong>'.esc_html($to).'</strong>.</p></div>'
                : '<div class="notice notice-error"><p>Email failed. Check Fluent SMTP logs.</p></div>';
        }
    }
}


/* =========================================================
 * 8) RENDER HELPERS (STATUS + LABELS)
 * ========================================================= */

function inviacco_invite_ui_status( $invite_data ) {

    $used      = ! empty( $invite_data['used'] );        // conceptually: redeemed
    $expired   = inviacco_is_expired( $invite_data );  // terminal
    $purchased = ! empty( $invite_data['purchased'] );   // overlay

    // Expired takes precedence over redeemed
    if ( $expired ) {
        $status = 'EXPIRED';
        $class  = 'expired';
    } elseif ( $used ) {
        $status = 'REDEEMED';
        $class  = 'redeemed';
    } else {
        $status = 'ISSUED';
        $class  = 'issued';
    }

    $exp_label = $expired ? 'Expired' : 'Expires';

    return [
        'used'      => $used,
        'expired'   => $expired,
        'purchased' => $purchased,
        'status'    => $status,
        'class'     => $class,
        'exp_label' => $exp_label,
    ];
}
/* =========================================================
 * 9) PAGE RENDER (HTML + CSS + JS FILTER + SORT + SEARCH)
 * ========================================================= */

function inviacco_invite_admin_page() {

    inviacco_handle_invite_actions();

    $invites = inviacco_load_all_invites();
    ?>
    <div class="wrap">
        <h1>Invites & Access Control</h1>

        <?php $inviacco_enabled = inviacco_invites_enabled(); ?>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:10px 0 16px;">
            <div style="padding:6px 10px;border-radius:999px;border:1px solid #dcdcde;background:#fff;">
                <strong>System:</strong>
                <span style="font-weight:700;<?php echo $inviacco_enabled ? 'color:#0a7a2f;' : 'color:#9b1c1c;'; ?>">
                    <?php echo $inviacco_enabled ? 'ON (invite-only)' : 'OFF (shop open to all)'; ?>
                </span>
            </div>

            <form method="POST" style="margin:0;">
                <?php wp_nonce_field('inviacco_invites_toggle'); ?>
                <button class="button <?php echo $inviacco_enabled ? 'button-secondary' : 'button-primary'; ?>"
                        name="inviacco_toggle_invites" value="1">
                    <?php echo $inviacco_enabled ? 'Turn OFF (Open Shop)' : 'Turn ON (Invite-Only)'; ?>
                </button>
            </form>

            <?php if ( ! $inviacco_enabled ) : ?>
                <span style="color:#666;">Invite history remains visible. New invite generation is disabled while OFF.</span>
            <?php endif; ?>
        </div>


        <!-- BRAND / EMAIL SETTINGS -->
        <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;margin:16px 0;">
            <h2 style="margin-top:0;">Brand & Email Settings</h2>
            <form method="POST">
                <?php wp_nonce_field('inviacco_brand_settings'); ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
                    <p><label><strong>Site / Brand Name</strong></label><br><input type="text" name="inviacco_brand_site_name" class="regular-text" value="<?php echo esc_attr(inviacco_get_brand_settings()['site_name']); ?>"></p>
                    <p><label><strong>Website URL</strong></label><br><input type="url" name="inviacco_brand_site_url" class="regular-text" value="<?php echo esc_attr(inviacco_get_brand_settings()['site_url']); ?>"></p>
                    <p><label><strong>From Name</strong></label><br><input type="text" name="inviacco_brand_from_name" class="regular-text" value="<?php echo esc_attr(inviacco_get_brand_settings()['from_name']); ?>"></p>
                    <p><label><strong>From Email</strong></label><br><input type="email" name="inviacco_brand_from_email" class="regular-text" value="<?php echo esc_attr(inviacco_get_brand_settings()['from_email']); ?>"></p>
                    <p><label><strong>Logo URL</strong></label><br><input type="url" name="inviacco_brand_logo_url" class="regular-text" value="<?php echo esc_attr(inviacco_get_brand_settings()['logo_url']); ?>"></p>
                </div>
                <p><button class="button" name="inviacco_save_brand_settings" value="1">Save Brand Settings</button></p>
            </form>
        </div>

        <!-- CREATE -->
        <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;margin:16px 0;" <?php echo $inviacco_enabled ? "" : "opacity:0.55;"; ?>>
            <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <?php wp_nonce_field('inviacco_invite_create'); ?>

                <input type="email" name="inviacco_invite_email" required placeholder="Recipient email" <?php echo $inviacco_enabled ? "" : "disabled"; ?>
                       style="width:320px;max-width:100%;padding:6px 10px;">

                <input type="number" name="inviacco_exp_days" value="7" min="1" max="365" <?php echo $inviacco_enabled ? "" : "disabled"; ?>
                       style="width:120px;padding:6px 10px;">

                <button class="button button-primary" name="inviacco_create_invite" value="1" <?php echo $inviacco_enabled ? "" : "disabled"; ?>>Create Invite</button>
            </form>
        </div>

	<!-- FILTER + SORT + SEARCH TOOLBAR -->
	<div class="pm-toolbar">
		<div class="pm-leftbar">
			<strong>View:</strong>
			<button class="pm-filter is-active" data-filter="all" type="button">All</button>
			<button class="pm-filter" data-filter="ISSUED" type="button">Issued</button>
			<button class="pm-filter" data-filter="REDEEMED" type="button">Redeemed</button>
			<button class="pm-filter" data-filter="EXPIRED" type="button">Expired</button>
			<button class="pm-filter" data-filter="PURCHASED" type="button">Purchased</button>

			<span class="pm-count" aria-live="polite">
				<strong>Showing:</strong> <span class="pm-count-num">0</span>
			</span>

			<span style="width:10px;display:inline-block;"></span>

			<strong>Search:</strong>
			<input class="pm-search" type="search" placeholder="email / token"
				   style="width:260px;max-width:100%;padding:4px 10px;border:1px solid #dcdcde;border-radius:8px;">
			<button class="pm-clear button" type="button">Clear</button>
		</div>

		<div class="pm-sort">
			<strong style="margin-right:6px;">Sort:</strong>
			<button class="pm-sortbtn" data-sort="email" data-dir="asc" type="button">Email ▲</button>
			<button class="pm-sortbtn" data-sort="status" data-dir="asc" type="button">Status ▲</button>
			<button class="pm-sortbtn" data-sort="expires" data-dir="asc" type="button">Expires ▲</button>
			<button class="pm-sortbtn is-active" data-sort="created" data-dir="desc" type="button">Created ▼</button>
		</div>
	</div>


        

        <?php foreach ($invites as $row):

            $token = $row['token'];
            $data  = $row['data'];

            $email      = $data['email'] ?? '';
            $expires_ts = intval($data['expires'] ?? 0);
            $created_ts = intval($data['created'] ?? 0);
            $days       = intval($data['exp_days'] ?? 7);

            $ui = inviacco_invite_ui_status($data);

            $expires = $expires_ts ? wp_date('Y-m-d', $expires_ts) : '—';
            $created = $created_ts ? wp_date('Y-m-d H:i', $created_ts) : '—';

            $link = inviacco_get_invite_link($token);

            $mark_used_url = wp_nonce_url(admin_url('admin.php?page=inviacco-invites&inviacco_mark_used='.$token),'inviacco_invite_actions');
            $mark_expired_url = wp_nonce_url(admin_url('admin.php?page=inviacco-invites&inviacco_mark_expired='.$token),'inviacco_invite_actions');
            $delete_url    = wp_nonce_url(admin_url('admin.php?page=inviacco-invites&inviacco_delete='.$token),'inviacco_invite_actions');
            $toggle_purchased_url = wp_nonce_url(admin_url('admin.php?page=inviacco-invites&inviacco_toggle_purchased='.$token),'inviacco_invite_actions');

            $default_subject = 'You’re invited to your store';
            $default_body    = inviacco_build_invite_email_html($token);

            $row_class = ($ui['used'] && $ui['purchased'] && !$ui['expired']) ? 'pm-purchased' : '';

            // For search: email + short token + full token (lowercased)
            $search_blob = strtolower(trim($email.' '.inviacco_token_short($token).' '.$token));
        ?>

		<details class="pm-acc <?php echo esc_attr($row_class); ?>"
				 data-status="<?php echo esc_attr($ui['status']); ?>"
				 data-purchased="<?php echo !empty($ui['purchased']) ? '1' : '0'; ?>"
				 data-email="<?php echo esc_attr(strtolower($email)); ?>"
				 data-expires="<?php echo esc_attr($expires_ts); ?>"
				 data-created="<?php echo esc_attr($created_ts); ?>"
				 data-search="<?php echo esc_attr($search_blob); ?>">


		<summary class="pm-sum">
			<div class="pm-left">

				<div>
					<?php
					// LEFT: single purchased indicator lives here (persistent)
					if ( ! empty($ui['purchased']) ) {

						// If it's redeemable & active, keep it clickable (toggle off possible)
						if ( $ui['used'] && ! $ui['expired'] ) { ?>
							<a class="pm-purchase-toggle is-on"
							   href="<?php echo esc_url($toggle_purchased_url); ?>"
							   title="Toggle purchase flag">
								Purchased ✓
							</a>
						<?php } else { ?>
							<!-- Persistent, non-clickable Purchased pill -->
							<span class="pm-purchase-toggle is-on pm-purchase-static"
								  title="Purchased flag (locked)">
								Purchased ✓
							</span>
						<?php }

					} else {

						// Not purchased yet: only show the action when redeemed & not expired
						if ( $ui['used'] && ! $ui['expired'] ) { ?>
							<a class="pm-purchase-toggle"
							   href="<?php echo esc_url($toggle_purchased_url); ?>"
							   title="Mark whether this redeemed invite resulted in a purchase">
								Mark Purchased
							</a>
						<?php } else { ?>
							<span class="pm-purchase-placeholder"></span>
						<?php }
					}
					?>
				</div>

				<strong><?php echo esc_html($email); ?></strong>

				<!-- STATUS COLUMN: only the lifecycle pill (no extra Purchased pill here) -->
				<span class="pm-pill <?php echo esc_attr($ui['class']); ?>">
					<?php echo esc_html($ui['status']); ?>
				</span>

				<span class="pm-meta">
					<?php echo esc_html($ui['exp_label']); ?>: <?php echo esc_html($expires); ?>
					(<?php echo esc_html($days); ?>d)
				</span>

				<span class="pm-meta">
					Token: <code class="pm-codechip"><?php echo esc_html(inviacco_token_short($token)); ?></code>
				</span>

			</div>

			<span class="pm-meta">Created: <?php echo esc_html($created); ?></span>
		</summary>


            <!-- EXPANDED VIEW -->
            <div class="pm-body">

                <div class="pm-card">
                    <div class="pm-row" style="justify-content:space-between;">
                        <div class="pm-meta">Full token: <code class="pm-codechip" style="max-width:420px;"><?php echo esc_html($token); ?></code></div>
                        <div class="pm-actions">
                            <a class="button" href="<?php echo esc_url($mark_used_url); ?>">Mark Redeemed</a>

                            <?php if ( ! $ui['used'] && ! $ui['expired'] ): ?>
                                <a class="button" href="<?php echo esc_url($mark_expired_url); ?>"
                                   onclick="return confirm('Force-expire this invite now?');">
                                   Mark Expired
                                </a>
                            <?php endif; ?>

                            <?php if ( $ui['used'] && ! $ui['expired'] ): ?>
                                <a class="button" href="<?php echo esc_url($toggle_purchased_url); ?>">
                                    <?php echo $ui['purchased'] ? 'Purchased ✓ (toggle off)' : 'Mark Purchased'; ?>
                                </a>
                            <?php endif; ?>

                            <a class="button" href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('Delete this invite?');">Delete</a>
                        </div>
                    </div>

                    <div style="margin-top:12px;">
                        <strong>Invite Link</strong>
                        <input class="pm-input" readonly value="<?php echo esc_url($link); ?>">
                    </div>
                </div>

                <div class="pm-card">
                    <strong>Email (editable)</strong>

                    <form method="POST" style="margin-top:10px;">
                        <?php wp_nonce_field('inviacco_invite_send'); ?>

                        <div class="pm-row">
                            <input type="email" name="inviacco_to" required value="<?php echo esc_attr($email); ?>"
                                   style="width:320px;max-width:100%;padding:6px 10px;">
                            <button class="button button-primary" name="inviacco_send_invite" value="1">Send / Resend Email</button>
                        </div>

                        <div style="margin-top:10px;">
                            <div class="pm-meta" style="margin-bottom:6px;">Subject</div>
                            <input class="pm-input" type="text" name="inviacco_subject" value="<?php echo esc_attr($default_subject); ?>">
                        </div>

                        <div style="margin-top:10px;">
                            <div class="pm-meta" style="margin-bottom:6px;">Body (preview)</div>

                            <div style="border:1px solid #dcdcde;border-radius:10px;padding:12px;background:#fff;max-height:520px;overflow:auto;">
                                <?php echo wp_kses_post($default_body); ?>
                            </div>

                            <div class="pm-meta" style="margin-top:8px;">
                                This is a preview of the HTML email. If you need to tweak it, expand “Edit HTML” below.
                            </div>

                            <details style="margin-top:10px;">
                                <summary class="pm-meta" style="cursor:pointer;user-select:none;">Edit HTML</summary>
                                <textarea class="pm-input" name="inviacco_body" rows="10"
                                          style="margin-top:10px;font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;"><?php
                                    echo esc_textarea($default_body);
                                ?></textarea>
                            </details>
                        </div>
                    </form>
                </div>

            </div>
        </details>

        <?php endforeach; ?>

    </div>

			
	/* =========================================================
     * 11) FILTER + SORT + SEARCH (client-side, no reload)
     * ========================================================= */
			


<?php } 

/**
 * redirect
 */
add_filter( 'woocommerce_add_to_cart_redirect', 'inviacco_add_to_cart_redirect' );
function inviacco_add_to_cart_redirect() {
    return wc_get_cart_url();
}

/**
 * WP User Roles
 */
/**
 * Daily cleanup: remove inviacco_invited_customer role ONLY when their access window is expired.
 * (Keeps WP Users list accurate without nuking legacy users who don't have meta yet.)
 */
add_action('init', function() {
    if ( ! inviacco_invites_enabled() ) return;
    if ( ! wp_next_scheduled('inviacco_invite_cleanup_daily') ) {
        wp_schedule_event(time() + 300, 'daily', 'inviacco_invite_cleanup_daily');
    }
});

add_action('inviacco_invite_cleanup_daily', function() {
    
    if ( ! inviacco_invites_enabled() ) { return; }
$now = time();

    $users = get_users([
        'role'   => 'inviacco_invited_customer',
        'fields' => ['ID'],
        'number' => 2000,
    ]);

    foreach ( $users as $u ) {
        $user_id = $u->ID;
        $expires = intval( get_user_meta($user_id, '_inviacco_access_expires', true) );

        // Only revoke if expiry meta exists and is expired
        if ( $expires > 0 && $expires < $now ) {
            $user = new WP_User($user_id);
            $user->remove_role('inviacco_invited_customer');

            if ( empty($user->roles) ) {
                $user->add_role('customer');
            }
        }
    }
});
