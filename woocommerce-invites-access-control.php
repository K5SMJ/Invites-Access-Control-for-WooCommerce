<?php
/**
 * Plugin Name: WooCommerce Invites & Access Control
 * Description: Invite-only WooCommerce access system (token gating) with admin UI. Includes an on/off switch to open the shop to everyone while keeping invite history.
 * Version: 1.0.0
 * Author: WooCommerce
 * Requires Plugins: woocommerce
 */

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Master option:
 *  - 1 = invites ON (invite-only shopping)
 *  - 0 = invites OFF (shop open to everyone; invite history preserved; generator disabled)
 */
function wci_invites_enabled(): bool {
    return (bool) get_option('wci_invites_enabled', 1);
}

function wci_set_invites_enabled( bool $enabled ): void {
    update_option('wci_invites_enabled', $enabled ? 1 : 0);
}

/**
 * If the system is OFF, ensure any old scheduled cleanup hook is cleared.
 */
function wci_invites_maybe_disable_cron(): void {
    if ( ! wci_invites_enabled() ) {
        wp_clear_scheduled_hook('wci_invite_cleanup_daily');
    }
}
add_action('init', 'wci_invites_maybe_disable_cron', 1);


/**
 * Purchase gating:
 * - If invites are OFF, leave WooCommerce behavior unchanged (shop open to all).
 * - If invites are ON, require a valid invite window.
 */
add_filter( 'woocommerce_is_purchasable', function( $purchasable ) {
    if ( ! wci_invites_enabled() ) return $purchasable;
    return wci_user_can_shop();
}, 20 );

add_filter( 'woocommerce_variation_is_purchasable', function( $purchasable ) {
    if ( ! wci_invites_enabled() ) return $purchasable;
    return wci_user_can_shop();
}, 20 );


/**
 * read more button fix
 */
add_filter( 'woocommerce_product_add_to_cart_text', 'custom_woocommerce_read_more_text', 10, 2 );
function custom_woocommerce_read_more_text( $text, $product ) {
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

function wci_user_can_shop() {
    
    if ( ! wci_invites_enabled() ) return true;
if ( current_user_can('administrator') ) return true;

    $user_id = get_current_user_id();
    if ( ! $user_id ) return false;

    // Use roles (more reliable than current_user_can('invited_customer'))
    $user  = get_userdata($user_id);
    $roles = ( $user && ! empty($user->roles) ) ? (array) $user->roles : [];

    // Must have role
    if ( ! in_array('invited_customer', $roles, true) ) return false;

    // Must still be within the stored access window
    $expires = intval( get_user_meta($user_id, '_wci_access_expires', true) );

    // If no expiry recorded, treat as expired (matches "timed window" requirement)
    if ( $expires <= 0 ) return false;

    return time() <= $expires;
}


function wci_invite_option_key( $token ) {
    return 'wci_invite_' . $token;
}

function wci_get_invite_link( $token ) {
    return add_query_arg( 'invite', $token, wc_get_page_permalink('myaccount') );
}

function wci_is_expired( $invite ) {
    return ( is_array($invite) && !empty($invite['expires']) && time() > intval($invite['expires']) );
}

function wci_token_short( $token, $len = 8 ) {
    $token = (string) $token;
    return strlen($token) <= $len ? $token : substr($token, 0, $len) . '…';
}

/* =========================================================
 * 1) ROLE + SHOP ACCESS
 * ========================================================= */

add_action('init', function() {
    if ( ! get_role('invited_customer') ) {
        add_role('invited_customer', 'Invited Customer', ['read' => true]);
    }
});

/* =========================================================
 * 2) INVITE STORAGE (UNLIMITED) + EXP DAYS
 * ========================================================= */

function wci_create_invite( $email, $days = 7 ) { // default now 7
    $days  = max( 1, min( 365, intval( $days ) ) );
    $token = wp_generate_password( 24, false );

    $data = [
        'email'    => $email,
        'created'  => time(),
        'exp_days' => $days,
        'expires'  => strtotime('+' . $days . ' days'),
        'used'     => false,
    ];

    add_option( wci_invite_option_key($token), $data );

    return $token;
}

function wci_load_all_invites() {
    $invites = [];

    foreach ( wp_load_alloptions() as $key => $value ) {
        if ( strpos($key, 'wci_invite_') === 0 ) {
            $token = str_replace('wci_invite_', '', $key);
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
 *      - add invited_customer role
 *      - store user meta _wci_access_expires = invite['expires']
 *      - mark invite used (single-use)
 * ========================================================= */

/**
 * If someone hits any page with ?invite=TOKEN while logged OUT,
 * redirect them to My Account so they can login/register.
 */
add_action('template_redirect', function() {
    
    if ( ! wci_invites_enabled() ) return;
if ( is_user_logged_in() ) return;
    if ( empty($_GET['invite']) ) return;
    if ( is_account_page() ) return;

    $token = sanitize_text_field( wp_unslash($_GET['invite']) );
    wp_safe_redirect( wci_get_invite_link( $token ) );
    exit;
});

/**
 * Show banner on My Account login/register screen when invite is present.
 */
add_action('woocommerce_before_customer_login_form', function() {
    
    if ( ! wci_invites_enabled() ) return;
if ( empty($_GET['invite']) ) return;

    $token  = sanitize_text_field( wp_unslash($_GET['invite']) );
    $invite = get_option( wci_invite_option_key($token) );

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
function wci_capture_invite_token() {
    
    if ( ! wci_invites_enabled() ) { return; }
if ( empty($_GET['invite']) ) return;

    $token = sanitize_text_field( wp_unslash($_GET['invite']) );
    if ( ! $token ) return;

    $invite = get_option( wci_invite_option_key($token) );
    if ( ! is_array($invite) ) return;

    // Do not persist expired tokens
    if ( ! empty($invite['expires']) && time() > intval($invite['expires']) ) return;

    // WC session (best case)
    if ( function_exists('WC') && WC() && WC()->session ) {
        WC()->session->set('wci_invite_token', $token);
    }

    // Cookie fallback (3 hours)
    setcookie(
        'wci_invite_token',
        $token,
        time() + 3 * HOUR_IN_SECONDS,
        COOKIEPATH ?: '/',
        COOKIE_DOMAIN,
        is_ssl(),
        true
    );

    // Make immediately available in this request
    $_COOKIE['wci_invite_token'] = $token;
}
add_action('init', 'wci_capture_invite_token', 20);

/**
 * Get pending invite token:
 * URL → WC session → cookie
 */
function wci_get_pending_invite_token() {

    if ( ! empty($_GET['invite']) ) {
        return sanitize_text_field( wp_unslash($_GET['invite']) );
    }

    if ( function_exists('WC') && WC() && WC()->session ) {
        $t = WC()->session->get('wci_invite_token');
        if ( ! empty($t) ) return sanitize_text_field((string) $t);
    }

    if ( ! empty($_COOKIE['wci_invite_token']) ) {
        return sanitize_text_field((string) $_COOKIE['wci_invite_token']);
    }

    return '';
}

/**
 * Clear stored invite token after successful apply
 * (prevents sticky or reused tokens)
 */
function wci_clear_pending_invite_token() {

    if ( function_exists('WC') && WC() && WC()->session ) {
        WC()->session->__unset('wci_invite_token');
    }

    setcookie(
        'wci_invite_token',
        '',
        time() - 3600,
        COOKIEPATH ?: '/',
        COOKIE_DOMAIN,
        is_ssl(),
        true
    );

    unset($_COOKIE['wci_invite_token']);
}

/* =========================================================
 * 3C) APPLY INVITE ON REGISTER / LOGIN (AUTO-REDEEM)
 * ========================================================= */

/**
 * Apply an invite token to a user:
 * - requires valid token that exists + not expired
 * - if unused: mark used + store redeemed_at + store redeemed_user_id
 * - always: add invited_customer role (without wiping other roles)
 * - store user meta _wci_access_expires = invite['expires']
 * - clear pending token (session/cookie)
 */
function wci_apply_invite_to_user( $user_id, $token ) {

    
    if ( ! wci_invites_enabled() ) { return false; }
$user_id = intval($user_id);
    $token   = sanitize_text_field( (string) $token );

    if ( $user_id <= 0 || ! $token ) return false;

    $invite = get_option( wci_invite_option_key($token) );
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
    if ( $wp_user && ! in_array('invited_customer', (array) $wp_user->roles, true) ) {
        $wp_user->add_role('invited_customer');
    }

    // Store access window tied to invite expiry
    update_user_meta($user_id, '_wci_access_expires', $expires_ts);

    // Mark used if not already used
    if ( empty($invite['used']) ) {
        $invite['used']             = true;
        $invite['redeemed_at']      = time();
        $invite['redeemed_user_id'] = $user_id;
        update_option( wci_invite_option_key($token), $invite );
    }

    // Clear the token so it doesn't stick around
    wci_clear_pending_invite_token();

    return true;
}

/**
 * Try to apply pending invite to a user once they are logged in.
 * Works for:
 * - new registrations (after user created)
 * - existing user logins
 * - already-logged-in users landing with ?invite=...
 */
function wci_maybe_apply_invite_for_current_user( $user_id = 0 ) {

    $uid = $user_id ? intval($user_id) : get_current_user_id();
    if ( ! $uid ) return;

    $token = wci_get_pending_invite_token();
    if ( ! $token ) return;

    wci_apply_invite_to_user( $uid, $token );
}

// After registration
add_action('user_register', function($user_id){
    wci_maybe_apply_invite_for_current_user( $user_id );
}, 20);

// After login (fires on wp_signon)
add_action('wp_login', function($user_login, $user){
    if ( $user && ! empty($user->ID) ) {
        wci_maybe_apply_invite_for_current_user( $user->ID );
    }
}, 20, 2);

// Already logged in + hits invite link
add_action('wp_loaded', function(){
    if ( is_user_logged_in() && ! empty($_GET['invite']) ) {
        wci_maybe_apply_invite_for_current_user( get_current_user_id() );
    }
}, 20);

/* =========================================================
 * 4) HTML EMAIL (UNCHANGED LOOK) – DYNAMIC DAYS + STOCK NOTE
 * ========================================================= */

function wci_get_logo_url() {
    $settings = wci_get_brand_settings();
    return ! empty($settings['logo_url']) ? esc_url($settings['logo_url']) : '';
}

function wci_build_invite_email_html( $token ) {

    $invite_link = wci_get_invite_link( $token );
    $logo_url    = wci_get_logo_url();

    $invite = get_option( wci_invite_option_key( $token ) );
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
      You’re invited to the your store shop
    </h2>
    <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#111;">
      Howdy,<br><br>
      You’ve been invited to access the your store shop using this unique token.
    </p>

    <div style="margin:22px 0 18px 0;">
      <a href="'.esc_url($invite_link).'" style="background:#111;color:#fff;text-decoration:none;padding:12px 18px;border-radius:6px;display:inline-block;font-weight:600;">
        Accept Your Invite
      </a>
    </div>

    <p style="margin:0 0 20px 0;font-size:14px;line-height:1.6;color:#222;">
      This token is directly tied to your invite, can only be used once, and will expire in '.$days.' days.
    </p>

    <p style="margin:0 0 22px 0;padding:12px 14px;background:#fafafa;border-left:3px solid #ddd;font-size:13px;line-height:1.6;color:#444;">
      <strong>Note:</strong> Inventory is limited. While your invite is valid for '.$days.' days,
      access may close earlier if I sell out. If your invite link stops working before the expiration date,
      it probably means I&#39;m out of stock.
    </p>

    <p style="margin:26px 0 0 0;font-size:14px;line-height:1.5;color:#111;">
      — your store<br>
      <a href="https://wci.com" style="color:#555;text-decoration:none;">https://wci.com</a>
    </p>

    <p style="margin:10px 0 0 0;font-size:11px;line-height:1.4;color:#999;">
      invite token: '.esc_html($token).'
    </p>
  </div>
</div>';
}

/* Ensure HTML email + stable From headers (PHP-safe, no arrow functions) */
add_filter( 'wp_mail_content_type', 'wci_mail_content_type' );
function wci_mail_content_type( $content_type ) {
    return 'text/html; charset=UTF-8';
}

add_filter( 'wp_mail_from_name', 'wci_mail_from_name' );
function wci_mail_from_name( $name ) {
    return 'your store';
}

add_filter( 'wp_mail_from', 'wci_mail_from' );
function wci_mail_from( $email ) {
    return 'sean@wci.com';
}


/* =========================================================
 * 5) EMAIL FAILURE DEBUG
 * ========================================================= */

add_action('wp_mail_failed', function( $wp_error ) {
    set_transient('wci_last_mail_error', $wp_error->get_error_message(), 10 * MINUTE_IN_SECONDS);
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
    add_menu_page(
        'your store Invites',
        'WooCommerce Invites',
        'manage_options',
        'wci-invites',
        'wci_invite_admin_page',
        'dashicons-email',
        25
    );
});

/* =========================================================
 * 7) ACTION HANDLERS (CREATE / MARK USED / TOGGLE PURCHASED / FORCE EXPIRE / DELETE / SEND)
 * ========================================================= */

function wci_handle_invite_actions() {

    

    // TOGGLE SYSTEM (ON/OFF)
    if ( isset($_POST['wci_toggle_invites']) && check_admin_referer('wci_invites_toggle') ) {
        wci_set_invites_enabled( ! wci_invites_enabled() );
        wci_invites_maybe_disable_cron();
        echo '<div class="notice notice-success"><p>Invite system is now <strong>' . ( wci_invites_enabled() ? 'ON' : 'OFF' ) . '</strong>.</p></div>';
    }

// CREATE INVITE
    if ( isset($_POST['wci_create_invite']) && check_admin_referer('wci_invite_create') ) {
        
        if ( ! wci_invites_enabled() ) {
            echo '<div class=\"notice notice-warning\"><p>Invite system is currently <strong>OFF</strong>. New invites are disabled. Turn the system ON to create new invites.</p></div>';
            return;
        }
$email = sanitize_email($_POST['wci_invite_email'] ?? '');
        $days  = max(1, min(365, intval($_POST['wci_exp_days'] ?? 7)));

        if ( ! is_email($email) ) {
            echo '<div class="notice notice-error"><p>Email is required (and must be valid).</p></div>';
        } else {
            wci_create_invite($email, $days);
            echo '<div class="notice notice-success"><p>Invite created for <strong>'.esc_html($email).'</strong> ('.$days.' days).</p></div>';
        }
    }

    // FORCE-EXPIRE (admin-only) — only meaningful for ISSUED; UI hides it for redeemed/expired
    if ( isset($_GET['wci_mark_expired']) && check_admin_referer('wci_invite_actions') ) {
        $token  = sanitize_text_field( wp_unslash($_GET['wci_mark_expired']) );
        $invite = get_option( wci_invite_option_key($token) );

        if ( is_array($invite) ) {
            // Force expiration into the past so your existing status logic shows EXPIRED
            $invite['expires'] = time() - 60;

            update_option( wci_invite_option_key($token), $invite );

            echo '<div class="notice notice-success"><p>Invite marked as expired.</p></div>';
        }
    }

	// MARK USED (REDEEMED)
	if ( isset($_GET['wci_mark_used']) && check_admin_referer('wci_invite_actions') ) {
		$token  = sanitize_text_field( wp_unslash($_GET['wci_mark_used']) );
		$invite = get_option( wci_invite_option_key($token) );

		if ( is_array($invite) ) {
			$invite['used'] = true;
			if ( empty($invite['redeemed_at']) ) $invite['redeemed_at'] = time();
			update_option( wci_invite_option_key($token), $invite );
			echo '<div class="notice notice-success"><p>Invite marked as redeemed.</p></div>';
		}
	}


    // TOGGLE PURCHASED (overlay; meaningful on REDEEMED only)
    if ( isset($_GET['wci_toggle_purchased']) && check_admin_referer('wci_invite_actions') ) {
        $token  = sanitize_text_field( wp_unslash($_GET['wci_toggle_purchased']) );
        $invite = get_option( wci_invite_option_key($token) );

        if ( is_array($invite) ) {
            $invite['purchased'] = empty($invite['purchased']) ? true : false;
            update_option( wci_invite_option_key($token), $invite );
            echo '<div class="notice notice-success"><p>Purchase flag updated.</p></div>';
        }
    }

    // DELETE
    if ( isset($_GET['wci_delete']) && check_admin_referer('wci_invite_actions') ) {
        $token = sanitize_text_field( wp_unslash($_GET['wci_delete']) );
        delete_option( wci_invite_option_key($token) );
        echo '<div class="notice notice-success"><p>Invite deleted.</p></div>';
    }

    // SEND / RESEND (editable subject/body)
    if ( isset($_POST['wci_send_invite']) && check_admin_referer('wci_invite_send') ) {
        $to      = sanitize_email($_POST['wci_to'] ?? '');
        $subject = sanitize_text_field($_POST['wci_subject'] ?? '');
        $body    = wp_kses_post($_POST['wci_body'] ?? '');

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

function wci_invite_ui_status( $invite_data ) {

    $used      = ! empty( $invite_data['used'] );        // conceptually: redeemed
    $expired   = wci_is_expired( $invite_data );  // terminal
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

function wci_invite_admin_page() {

    wci_handle_invite_actions();

    $invites = wci_load_all_invites();
    ?>
    <div class="wrap">
        <h1>WooCommerce Invites</h1>

        <?php $wci_enabled = wci_invites_enabled(); ?>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:10px 0 16px;">
            <div style="padding:6px 10px;border-radius:999px;border:1px solid #dcdcde;background:#fff;">
                <strong>System:</strong>
                <span style="font-weight:700;<?php echo $wci_enabled ? 'color:#0a7a2f;' : 'color:#9b1c1c;'; ?>">
                    <?php echo $wci_enabled ? 'ON (invite-only)' : 'OFF (shop open to all)'; ?>
                </span>
            </div>

            <form method="POST" style="margin:0;">
                <?php wp_nonce_field('wci_invites_toggle'); ?>
                <button class="button <?php echo $wci_enabled ? 'button-secondary' : 'button-primary'; ?>"
                        name="wci_toggle_invites" value="1">
                    <?php echo $wci_enabled ? 'Turn OFF (Open Shop)' : 'Turn ON (Invite-Only)'; ?>
                </button>
            </form>

            <?php if ( ! $wci_enabled ) : ?>
                <span style="color:#666;">Invite history remains visible. New invite generation is disabled while OFF.</span>
            <?php endif; ?>
        </div>


        <!-- CREATE -->
        <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;margin:16px 0;" <?php echo $wci_enabled ? "" : "opacity:0.55;"; ?>>
            <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <?php wp_nonce_field('wci_invite_create'); ?>

                <input type="email" name="wci_invite_email" required placeholder="Recipient email" <?php echo $wci_enabled ? "" : "disabled"; ?>
                       style="width:320px;max-width:100%;padding:6px 10px;">

                <input type="number" name="wci_exp_days" value="7" min="1" max="365" <?php echo $wci_enabled ? "" : "disabled"; ?>
                       style="width:120px;padding:6px 10px;">

                <button class="button button-primary" name="wci_create_invite" value="1" <?php echo $wci_enabled ? "" : "disabled"; ?>>Create Invite</button>
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


        <style>
        /* Toolbar */
        .pm-toolbar{
            display:flex;
            gap:10px;
            align-items:center;
            justify-content:space-between;
            padding:10px 12px;
            background:#f6f7f8;
            border:1px solid #dcdcde;
            border-radius:8px;
            margin-bottom:12px;
            flex-wrap:wrap;
        }
        .pm-leftbar{
            display:flex;
            gap:8px;
            align-items:center;
            flex-wrap:wrap;
        }
        .pm-filter,
        .pm-sortbtn{
            padding:4px 10px;
            border-radius:6px;
            border:1px solid #dcdcde;
            background:#fff;
            cursor:pointer;
            font-size:12px;
            user-select:none;
        }
        .pm-filter.is-active,
        .pm-sortbtn.is-active{
            background:#111;
            color:#fff;
            border-color:#111;
        }
        .pm-sort{
            display:flex;
            gap:8px;
            align-items:center;
            flex-wrap:wrap;
        }

        /* Accordion shell */
        .pm-acc{
            border:1px solid #dcdcde;
            border-radius:12px;
            margin:10px 0;
            background:#fff;
            overflow:hidden;
        }
        .pm-sum{
            display:grid;
            grid-template-columns: 1fr auto;
            gap:12px;
            padding:12px 16px;
            background:#f6f7f8;
            cursor:pointer;
            align-items:center;
        }
        .pm-acc[open]>.pm-sum{ background:#eef0f2; }
        .pm-sum:hover{ background:#eceff1; }

        /* Purchased overlay tint (keeps pill colors unchanged) */
        .pm-acc.pm-purchased > .pm-sum{ background:#eaf3ff; }
        .pm-acc.pm-purchased[open] > .pm-sum{ background:#dfeeff; }

        /* =========================================================
         * 10) COLUMN CLEANUP (more even padding + alignment)
         * ========================================================= */

        /* Purchase toggle | Email | Pill | Exp | Token */
        .pm-left{
            display:grid;
            grid-template-columns: 132px minmax(280px, 1fr) 108px 260px 190px;
            column-gap:18px;
            align-items:center;
        }
        .pm-left > *{ min-width:0; }

        .pm-left strong{
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }

        .pm-meta{
            font-size:12px;
            color:#666;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }

        .pm-sum > .pm-meta{
            justify-self:end;
            padding-left:12px;
        }

        .pm-row{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .pm-input{ width:100%; padding:8px 10px; }

        code.pm-codechip{
            display:inline-block;
            max-width:160px;
            overflow:hidden;
            text-overflow:ellipsis;
            vertical-align:middle;
            background:#f3f4f6;
            border:1px solid #e5e7eb;
            padding:2px 6px;
            border-radius:6px;
            font-size:12px;
        }

        /* Token lifecycle pills */
        .pm-pill{
            padding:4px 12px;
            min-width:88px;
            text-align:center;
            border-radius:999px;
            font-weight:800;
            letter-spacing:0.04em;
            margin-right:6px;
            border:1px solid transparent;
            font-size:12px;
        }
        .pm-pill.issued{   background:#dbeafe; color:#1e40af; border-color:#3b82f6; }
        .pm-pill.redeemed{ background:#e5e7eb; color:#111827; border-color:#9ca3af; }
        .pm-pill.expired{  background:#fecaca; color:#7f1d1d; border-color:#ef4444; }

						 /* Pill stack: allows Purchased + Status in same column on one line */
		.pm-pillstack{
			display:inline-flex;
			align-items:center;
			gap:8px;
			flex-wrap:nowrap;
		}

		/* Purchased pill (green) */
		.pm-pill.purchased{
			background:#dcfce7;
			color:#14532d;
			border-color:#16a34a;
		}


        /* Purchase toggle */
        .pm-purchase-toggle{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:6px;
            border:1px solid #dcdcde;
            background:#fff;
            border-radius:999px;
            padding:4px 10px;
            font-size:12px;
            cursor:pointer;
            text-decoration:none;
            color:#111;
            white-space:nowrap;
        }
        .pm-purchase-toggle:hover{ background:#f0f0f1; }
        .pm-purchase-toggle.is-on{
            border-color:#16a34a;
            background:#dcfce7;
            color:#14532d;
            font-weight:800;
        }
		.pm-purchase-placeholder{ display:inline-block; height:28px; }

		/* 🔢 Count badge (NEW) */
		.pm-count{
			display:inline-flex;
			align-items:center;
			gap:6px;
			margin-left:10px;
			padding:4px 10px;
			border:1px solid #dcdcde;
			background:#fff;
			border-radius:999px;
			font-size:12px;
		}
		.pm-count-num{
			font-weight:800;
		}
		</style>

        <?php foreach ($invites as $row):

            $token = $row['token'];
            $data  = $row['data'];

            $email      = $data['email'] ?? '';
            $expires_ts = intval($data['expires'] ?? 0);
            $created_ts = intval($data['created'] ?? 0);
            $days       = intval($data['exp_days'] ?? 7);

            $ui = wci_invite_ui_status($data);

            $expires = $expires_ts ? date('Y-m-d', $expires_ts) : '—';
            $created = $created_ts ? date('Y-m-d H:i', $created_ts) : '—';

            $link = wci_get_invite_link($token);

            $mark_used_url = wp_nonce_url(admin_url('admin.php?page=wci-invites&wci_mark_used='.$token),'wci_invite_actions');
            $mark_expired_url = wp_nonce_url(admin_url('admin.php?page=wci-invites&wci_mark_expired='.$token),'wci_invite_actions');
            $delete_url    = wp_nonce_url(admin_url('admin.php?page=wci-invites&wci_delete='.$token),'wci_invite_actions');
            $toggle_purchased_url = wp_nonce_url(admin_url('admin.php?page=wci-invites&wci_toggle_purchased='.$token),'wci_invite_actions');

            $default_subject = 'You’re invited to your store';
            $default_body    = wci_build_invite_email_html($token);

            $row_class = ($ui['used'] && $ui['purchased'] && !$ui['expired']) ? 'pm-purchased' : '';

            // For search: email + short token + full token (lowercased)
            $search_blob = strtolower(trim($email.' '.wci_token_short($token).' '.$token));
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
					Token: <code class="pm-codechip"><?php echo esc_html(wci_token_short($token)); ?></code>
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
                        <?php wp_nonce_field('wci_invite_send'); ?>

                        <div class="pm-row">
                            <input type="email" name="wci_to" required value="<?php echo esc_attr($email); ?>"
                                   style="width:320px;max-width:100%;padding:6px 10px;">
                            <button class="button button-primary" name="wci_send_invite" value="1">Send / Resend Email</button>
                        </div>

                        <div style="margin-top:10px;">
                            <div class="pm-meta" style="margin-bottom:6px;">Subject</div>
                            <input class="pm-input" type="text" name="wci_subject" value="<?php echo esc_attr($default_subject); ?>">
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
                                <textarea class="pm-input" name="wci_body" rows="10"
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
			
<script>
(function(){
  const rows = () => Array.from(document.querySelectorAll('.pm-acc'));
  const firstRow = document.querySelector('.pm-acc');
  if(!firstRow) return;
  const parent = firstRow.parentElement;

  const storageKey = 'wci_invites_view_state_v2';

  function loadState(){
    try{ return JSON.parse(localStorage.getItem(storageKey) || '{}'); }
    catch(e){ return {}; }
  }
  function saveState(state){
    try{ localStorage.setItem(storageKey, JSON.stringify(state)); }
    catch(e){}
  }

  const state = Object.assign({
    filter: 'all',
    search: '',
    sortKey: 'created',
    sortDir: 'desc'
  }, loadState());

  /* ---------- FILTER ---------- */
  const filterBtns = Array.from(document.querySelectorAll('.pm-filter'));
  filterBtns.forEach(btn=>{
    btn.addEventListener('click', ()=>{
      filterBtns.forEach(b=>b.classList.remove('is-active'));
      btn.classList.add('is-active');
      state.filter = btn.dataset.filter || 'all';
      saveState(state);
      applyAll();
    });
  });

  /* ---------- SEARCH ---------- */
  const searchInput = document.querySelector('.pm-search');
  const clearBtn = document.querySelector('.pm-clear');

  if(searchInput){
    searchInput.addEventListener('input', ()=>{
      state.search = (searchInput.value || '').trim().toLowerCase();
      saveState(state);
      applyAll();
    });
  }

  if(clearBtn){
    clearBtn.addEventListener('click', ()=>{
      state.search = '';
      saveState(state);
      if(searchInput) searchInput.value = '';
      applyAll();
    });
  }

  /* ---------- SORT ---------- */
  const sortBtns = Array.from(document.querySelectorAll('.pm-sortbtn'));

  function normalizeArrow(btn){
    const dir = btn.dataset.dir || 'asc';
    const base = btn.textContent.replace('▲','').replace('▼','').trim();
    btn.textContent = base + (dir === 'asc' ? ' ▲' : ' ▼');
  }

  function compare(a, b, key, dir){
    const va = a.dataset[key] ?? '';
    const vb = b.dataset[key] ?? '';

    if(key === 'expires' || key === 'created'){
      const na = parseInt(va || '0', 10);
      const nb = parseInt(vb || '0', 10);
      return dir === 'asc' ? (na - nb) : (nb - na);
    }

    if(key === 'status'){
      const order = { ISSUED:1, REDEEMED:2, EXPIRED:3 };
      return dir === 'asc'
        ? (order[va] || 99) - (order[vb] || 99)
        : (order[vb] || 99) - (order[va] || 99);
    }

    return dir === 'asc'
      ? String(va).localeCompare(String(vb))
      : String(vb).localeCompare(String(va));
  }

  function applySort(key, dir){
    rows()
      .map((r,i)=>({r,i}))
      .sort((A,B)=>{
        const c = compare(A.r, B.r, key, dir);
        return c !== 0 ? c : A.i - B.i;
      })
      .forEach(o=>parent.appendChild(o.r));
  }

  sortBtns.forEach(btn=>{
    normalizeArrow(btn);
    btn.addEventListener('click', ()=>{
      btn.dataset.dir = btn.dataset.dir === 'asc' ? 'desc' : 'asc';
      sortBtns.forEach(b=>b.classList.remove('is-active'));
      btn.classList.add('is-active');
      state.sortKey = btn.dataset.sort;
      state.sortDir = btn.dataset.dir;
      saveState(state);
      applyAll();
    });
  });

  /* ---------- FILTER + COUNT ---------- */
  function applyFilterAndSearch(){
    const f = state.filter || 'all';
    const q = (state.search || '').trim().toLowerCase();
    let visible = 0;

    rows().forEach(r=>{
      const status = r.dataset.status;
      const purchased = r.dataset.purchased === '1';

      const matchesFilter =
        (f === 'all') ||
        (f === 'PURCHASED' ? purchased : status === f);

      const matchesSearch =
        !q || (r.dataset.search || '').toLowerCase().includes(q);

      const show = matchesFilter && matchesSearch;
      r.style.display = show ? '' : 'none';
      if(show) visible++;
    });

    const countEl = document.querySelector('.pm-count-num');
    if(countEl) countEl.textContent = visible;
  }

  function applyAll(){
    applySort(state.sortKey, state.sortDir);
    applyFilterAndSearch();
    sortBtns.forEach(normalizeArrow);
  }

  /* ---------- INIT ---------- */
  filterBtns.forEach(b=>{
    b.classList.toggle('is-active', b.dataset.filter === state.filter);
  });
  if(searchInput) searchInput.value = state.search || '';
  applyAll();
})();
</script>


<?php } 

/**
 * Admin-only invite email preview sender
 * Usage:
 * https://yoursite.com/wp-admin/?wci_preview_invite_email=TOKEN
 */
add_action('admin_init', function () {

    // Safety: admin only
    if ( ! current_user_can('administrator') ) {
        return;
    }

    // Require token
    if ( empty($_GET['wci_preview_invite_email']) ) {
        return;
    }

    $token = sanitize_text_field($_GET['wci_preview_invite_email']);

    // Build email HTML using your real function
    $html = wci_build_invite_email_html( $token );

    wp_mail(
        get_option('admin_email'), // send to yourself
        'Pocket MIDI – Invite Email Preview',
        $html,
        [ 'Content-Type: text/html; charset=UTF-8' ]
    );

    wp_die('Invite email preview sent. Check your inbox.');
});

/**
 * redirect
 */
add_filter( 'woocommerce_add_to_cart_redirect', 'custom_add_to_cart_redirect' );
function custom_add_to_cart_redirect() {
    return wc_get_cart_url();
}

/**
 * WP User Roles
 */
/**
 * Daily cleanup: remove invited_customer role ONLY when their access window is expired.
 * (Keeps WP Users list accurate without nuking legacy users who don't have meta yet.)
 */
add_action('init', function() {
    if ( ! wci_invites_enabled() ) return;
    if ( ! wp_next_scheduled('wci_invite_cleanup_daily') ) {
        wp_schedule_event(time() + 300, 'daily', 'wci_invite_cleanup_daily');
    }
});

add_action('wci_invite_cleanup_daily', function() {
    
    if ( ! wci_invites_enabled() ) { return; }
$now = time();

    $users = get_users([
        'role'   => 'invited_customer',
        'fields' => ['ID'],
        'number' => 2000,
    ]);

    foreach ( $users as $u ) {
        $user_id = $u->ID;
        $expires = intval( get_user_meta($user_id, '_wci_access_expires', true) );

        // Only revoke if expiry meta exists and is expired
        if ( $expires > 0 && $expires < $now ) {
            $user = new WP_User($user_id);
            $user->remove_role('invited_customer');

            if ( empty($user->roles) ) {
                $user->add_role('customer');
            }
        }
    }
});
