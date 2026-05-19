=== CW → SF Bridge ===
Contributors: verdian
Tags: woocommerce, memberships, teams, webhook, salesforce, n8n
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Canonical event bridge from ComplianceWeek (WP + WooCommerce + Memberships + Teams) to Salesforce via N8N. v1.0.0 handles pending team invitations; future versions add memberships, teams, users, and admin-scope REST endpoints.

== Description ==

This is the umbrella plugin for all ComplianceWeek → Salesforce integration. New modules and entity bridges are added here as the integration grows.

**v1.0.0 scope — Pending team invitations**

The WC Memberships for Teams plugin does not expose a native WooCommerce webhook topic for team invitations. Pending invitations (created when a team owner or admin invites someone by email, before the recipient accepts) are therefore invisible to the existing user_membership.* webhooks already in use.

This module closes that gap by hooking the `wc_memberships_for_teams_invitation_created` action and POSTing a structured JSON payload to a configured webhook URL. The payload covers every relevant field (invitation id, team id/name, email, role, sender, dates) and is filterable.

Fires on every invitation_created event regardless of origin:

- Frontend: team owner invites from their Team Settings page
- Backend: WP admin adds a member via the Teams admin screen
- REST API: external client hits `/wp-json/wc-memberships-for-teams/v1/invitations`
- WP-CLI: any CLI script that creates an invitation

The request is fire-and-forget (non-blocking) so the user-facing invitation flow is not delayed by webhook latency.

**Roadmap**

- v1.1+ — Memberships module: enrich `user_membership.*` events with extracted `_team_id` meta so N8N doesn't have to parse `meta_data`
- v1.2+ — Teams module: hook team CRUD events directly
- v1.3+ — Users module: relevant profile/role updates
- v2.0  — Admin-scope REST endpoints under `/wp-json/cw-sf/v1/` that bypass the user-scope restrictions of native WCMfT abilities (no more N+1 calls to enumerate invitations across all teams)

== Installation ==

1. Upload the plugin .zip via **WP Admin → Plugins → Add New → Upload Plugin**. Do NOT activate yet.

2. Add the required constants to `wp-config.php`, above the line `/* That's all, stop editing! */`:

   `define( 'CWSF_WEBHOOK_URL', 'https://verdian.app.n8n.cloud/webhook/team-invitation-pending' );`
   `define( 'CWSF_SHARED_SECRET', 'long-random-secret-here' );`

   Generate the secret with `openssl rand -hex 32` or any cryptographically random source. The same value must be configured in the receiving N8N webhook node as the `X-API-Key` header value.

3. (Optional) For the first deploy only, enable verbose logging to verify the hook fires and to confirm the installed Invitation class exposes the expected method names:

   `define( 'CWSF_DEBUG_LOG', true );`

   This dumps the class name and full method list to PHP error_log on every hook fire. Remove this line after the first successful invitation passes through.

4. Activate the plugin via **WP Admin → Plugins**.

5. Verify there is no red "missing required constants" admin notice. If it appears, recheck step 2.

6. Create a test invitation (from any team's settings page or admin) and confirm it arrives at the N8N webhook.

== Configuration ==

**Required constants (wp-config.php):**

- `CWSF_WEBHOOK_URL` — full HTTPS URL of the receiving webhook
- `CWSF_SHARED_SECRET` — value sent in the `X-API-Key` header; the receiver must validate this

**Optional constants:**

- `CWSF_DEBUG_LOG` (bool, default false) — verbose logging of hook fires
- `CWSF_TIMEOUT` (int, default 8) — outgoing HTTP request timeout in seconds

**Filter hooks for extension:**

- `cwsf_invitation_payload` ( array $payload, object $invitation ) — modify the JSON payload before sending
- `cwsf_after_invitation_sent` ( array $payload, mixed $response, object $invitation ) — action fired after the POST attempt

== Payload format ==

```
{
  "event": "invitation.created",
  "invitation_id": 104450,
  "team_id": 101202,
  "team_name": "ECI Compliance Week",
  "email": "user@example.com",
  "status": "pending",
  "role": "member",
  "sender_id": 6791,
  "date_created": "2026-05-19T14:30:00+00:00",
  "site_url": "https://www.complianceweek.com",
  "fired_at": "2026-05-19T14:30:01+00:00"
}
```

== Failure modes ==

The bridge fails-soft. If the receiving endpoint is unreachable or returns an error, the invitation creation completes normally (the user sees no failure) and the error is logged to PHP error_log with prefix `[cwsf]`. Lost events should be reconciled by a periodic backfill job that queries `/wp-json/wc-memberships-for-teams/v1/invitations?status=pending` per team and upserts to the destination system.

== Changelog ==

= 1.0.0 =
* Initial release. Hooks `wc_memberships_for_teams_invitation_created`, POSTs JSON payload to configured webhook URL.
