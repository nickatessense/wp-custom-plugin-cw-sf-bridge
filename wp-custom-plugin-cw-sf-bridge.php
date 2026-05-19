<?php
/**
 * Plugin Name:       CW → SF Bridge
 * Plugin URI:        https://github.com/nickatessense/wp-custom-plugin-cw-sf-bridge
 * Description:       Canonical event bridge from ComplianceWeek (WP + WooCommerce + Memberships + Teams) to Salesforce via N8N. v1.0.0 module: pending team invitations — fires on every wc_memberships_for_teams_invitation_created regardless of origin (frontend owner UI, backend admin, REST API, WP-CLI). Future versions will add memberships, teams, and users modules plus admin-scope REST endpoints.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Verdian
 * Author URI:        https://verdianinsights.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-custom-plugin-cw-sf-bridge
 */

defined( 'ABSPATH' ) || exit;

const CWSF_VERSION = '1.0.0';

/**
 * REQUIRED constants (set in wp-config.php, above the "stop editing" line):
 *
 *   define( 'CWSF_WEBHOOK_URL',    'https://verdian.app.n8n.cloud/webhook/team-invitation-pending' );
 *   define( 'CWSF_SHARED_SECRET',  'long-random-secret-here' );
 *
 * OPTIONAL constants:
 *
 *   define( 'CWSF_DEBUG_LOG', true );   // dumps hook fires + Invitation class methods to error_log (use during initial deploy, then remove)
 *   define( 'CWSF_TIMEOUT',   8 );      // outgoing HTTP timeout in seconds (default 8)
 */

/**
 * Admin notice if required constants are missing — fail loud, not silent.
 */
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $missing = array_filter( [
        ! defined( 'CWSF_WEBHOOK_URL' )   ? 'CWSF_WEBHOOK_URL'   : null,
        ! defined( 'CWSF_SHARED_SECRET' ) ? 'CWSF_SHARED_SECRET' : null,
    ] );
    if ( empty( $missing ) ) {
        return;
    }
    printf(
        '<div class="notice notice-error"><p><strong>CW → SF Bridge:</strong> missing required wp-config.php constant(s): <code>%s</code>. The bridge is inactive until these are defined.</p></div>',
        esc_html( implode( '</code>, <code>', $missing ) )
    );
} );

/**
 * Main hook: fires whenever a WC Memberships for Teams invitation is created,
 * regardless of origin (frontend owner UI, backend admin add, REST API, WP-CLI).
 *
 * @param object $invitation Instance of \SkyVerge\WooCommerce\Memberships\Teams\Invitation
 */
add_action( 'wc_memberships_for_teams_invitation_created', function ( $invitation ) {

    if ( ! defined( 'CWSF_WEBHOOK_URL' ) || ! defined( 'CWSF_SHARED_SECRET' ) ) {
        return; // admin notice already warns; abort silently in the hot path
    }

    if ( ! $invitation || ! is_object( $invitation ) ) {
        error_log( '[cwsf] invalid invitation argument received' );
        return;
    }

    if ( defined( 'CWSF_DEBUG_LOG' ) && CWSF_DEBUG_LOG ) {
        error_log( sprintf(
            '[cwsf] HOOK fired. class=%s methods=%s',
            get_class( $invitation ),
            implode( ',', get_class_methods( $invitation ) ?: [] )
        ) );
    }

    $payload = cwsf_build_payload( $invitation );

    $response = wp_remote_post( CWSF_WEBHOOK_URL, [
        'timeout'  => defined( 'CWSF_TIMEOUT' ) ? CWSF_TIMEOUT : 8,
        'blocking' => false, // fire-and-forget: don't block the invitation UX
        'headers'  => [
            'Content-Type' => 'application/json',
            'X-API-Key'    => CWSF_SHARED_SECRET,
            'User-Agent'   => 'CW-SF-Bridge/' . CWSF_VERSION,
        ],
        'body'     => wp_json_encode( $payload ),
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( sprintf(
            '[cwsf] POST failed invitation_id=%s err=%s',
            $payload['invitation_id'] ?? 'unknown',
            $response->get_error_message()
        ) );
    }

    do_action( 'cwsf_after_invitation_sent', $payload, $response, $invitation );
}, 10, 1 );

/**
 * Build the JSON payload from an Invitation object using defensive accessors,
 * so a method name mismatch in a newer/older plugin version degrades to NULL
 * instead of fatal-erroring the request.
 *
 * Filterable via 'cwsf_invitation_payload' for downstream extension.
 */
function cwsf_build_payload( $invitation ) {

    $get = static function ( $obj, $method, $default = null ) {
        return ( $obj && is_object( $obj ) && is_callable( [ $obj, $method ] ) )
            ? $obj->$method()
            : $default;
    };

    $team    = $get( $invitation, 'get_team' );
    $team_id = $get( $invitation, 'get_team_id', $get( $team, 'get_id' ) );

    $payload = [
        'event'         => 'invitation.created',
        'invitation_id' => $get( $invitation, 'get_id' ),
        'team_id'       => $team_id,
        'team_name'     => $get( $team, 'get_name' ),
        'email'         => $get( $invitation, 'get_email' ),
        'status'        => $get( $invitation, 'get_status' ),
        'role'          => $get( $invitation, 'get_role' ),
        'sender_id'     => $get( $invitation, 'get_sender_id' ),
        'date_created'  => $get( $invitation, 'get_date' ),
        'site_url'      => home_url(),
        'fired_at'      => gmdate( 'c' ),
    ];

    return apply_filters( 'cwsf_invitation_payload', $payload, $invitation );
}
