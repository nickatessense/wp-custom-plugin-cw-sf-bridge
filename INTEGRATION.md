# CW → SF Integration: Pending Team Invitations

**Status:** v1 in development as of 2026-05-19. First module of the `wp-custom-plugin-cw-sf-bridge` umbrella.
**Owners:** Diego (WP/N8N), Chris (WP deploys), Bobby + Morgan (SF & business rules).

## What this does

When a team owner or admin invites a new member by email on ComplianceWeek.com, that invitation has historically been invisible to Salesforce — no native webhook fires, and the invited person has no `user_membership` yet (one only exists if they accept).

This integration closes that gap: every new pending invitation flows in real time from WP → N8N → SF, where a placeholder Contact + pending `Newspack_Membership__c` record is created. When the invitee eventually accepts and logs in, the existing **User Membership Sync** flow takes over and fills in the real data.

## Architecture

```
[ComplianceWeek WP]
    │  Admin or team owner adds member from
    │  /my-account/teams/<team>/manage
    │
    ↓  fires action: wc_memberships_for_teams_invitation_created
    │
[Plugin: wp-custom-plugin-cw-sf-bridge v1.0.0]
    │  Builds JSON payload, POSTs with X-API-Key header
    │
    ↓  https://verdian.app.n8n.cloud/webhook/team-invitation-pending
    │
[N8N Workflow 1: CW SF Bridge — Invitation Webhook Receiver]
    │  Validates X-API-Key, normalizes payload, calls sub-workflow
    │
    ↓
[N8N Sub-workflow: CW SF Bridge — Pending Invitation to SF]
    │  Lookup Account → Query Contact → branch logic → create records
    │
    ↓
[Salesforce]
    Contact (new or existing) + Newspack_Membership__c (status="pending")
```

## Business rules & assumptions

Stakeholders should be aware of these — the integration depends on them being true:

### Hard requirements (flow SKIPS if not met)

| Rule | Why it exists | What happens if violated |
|---|---|---|
| The team must exist in SF as an `Account` (matched via `Newspack_Membership_Company_ID__c = team_id`) | We need to link the invitee Contact to the right Account. Without it, the Contact would float orphaned. | Invitation is silently skipped. Visible only in N8N execution logs as `skipped: team_account_not_found_in_sf`. **Action: ensure all CW teams have corresponding Accounts in SF before this flow goes live.** |
| The invitee's Contact (matched by Email) must NOT already have a `Newspack_Membership__c` populated | If the person already has a real membership, we don't want to overwrite or create a stale pending record on top. | Invitation is silently skipped. Logged as `skipped: contact_already_has_membership`. This is the expected behavior for re-invited existing members. |

### Soft assumptions (flow continues but data is limited)

- **The admin form only collects Email + Role**. So when the invitation event fires, those are the only "human" data points we have. Everything else (`team_id`, `team_name`, `sender_id`, `date_created`, `invitation_id`) is derived automatically by WP/the plugin.
- **The invitee's name is unknown at invitation time**. We create the Contact with `LastName = "(Pending invitee)"` as a placeholder. See "Open items" below.
- **Role is captured but not used in SF mapping** (yet). It's stored as `Membership_Role__c` on the pending record. If business decides role matters for downstream automation, it's already there.
- **The `Membership_ID__c` field on the pending record stays empty**. That field is reserved for real WP `user_membership.id` values. When the invitee accepts, the existing flow creates a separate record with that field populated.

## Data flow detail

### What gets created in SF when a pending invitation fires

**Case A — Contact does NOT exist in SF:**
1. Create new `Contact` with:
   - `Email` = invitee's email
   - `LastName` = `"(Pending invitee)"` (placeholder)
   - `FirstName` = empty
   - `AccountId` = the team's SF Account
2. Create new `Newspack_Membership__c` linked to that Contact with:
   - `Contact__c` = new Contact's Id
   - `Membership_Status__c` = `"pending"`
   - `Company_Membership_Team_Name__c` = team name
   - `Membership_Role__c` = `"member"` or `"manager"` (whatever the admin selected)
   - `Member_Since__c` = invitation creation date
   - All other fields empty

**Case B — Contact already exists (no membership):**
1. Re-use the existing Contact. **Nothing is modified on the Contact** (not even `Company_Name_Text__c`).
2. Create new `Newspack_Membership__c` linked to the existing Contact, same fields as above.

**Case C — Contact already exists with a membership:**
- Skip entirely. Log only.

**Case D — Team has no Account in SF:**
- Skip entirely. Log only. **This is the case that needs business attention.**

### What happens when the invitee accepts

The existing **User Membership Sync** flow handles this independently. It:
1. Receives `user_membership.created` from WC Memberships
2. Calls `Ensure Contact in SF` which matches our pre-created Contact by email and backfills `Newspack_Member_User_ID__c`
3. Creates a new `Newspack_Membership__c` (with real `Membership_ID__c`) for the now-active membership

**Result:** the Contact ends up with two `Newspack_Membership__c` records — the pending one we created, and the active one the existing flow creates. They coexist. See "Open items" below.

## Auth & secrets

| Secret | Where it lives | Used by |
|---|---|---|
| `CWSF_WEBHOOK_URL` | `wp-config.php` on CW | Plugin (target URL for the POST) |
| `CWSF_SHARED_SECRET` | `wp-config.php` on CW + N8N "Validate API Key" IF node | Plugin (sent in `X-API-Key` header), N8N (validates incoming) |

Auth is single shared secret in the header. The N8N webhook is internet-exposed; without a valid `X-API-Key` it returns 401.

To rotate: generate new secret with `openssl rand -hex 32`, update both `wp-config.php` and the N8N IF node, redeploy the plugin if needed (no rebuild required since secret is read at runtime).

## Components reference

| Component | Repo / Location | Notes |
|---|---|---|
| WP Plugin | github.com/nickatessense/wp-custom-plugin-cw-sf-bridge | v1.0.0 — first module of an umbrella that will grow with memberships, teams, users modules later |
| N8N Workflow 1 | n8n cloud — "CW SF Bridge — Invitation Webhook Receiver" | Webhook entry point |
| N8N Sub-workflow | n8n cloud — "CW SF Bridge — Pending Invitation to SF" | All SF logic here |
| Existing flow (untouched) | n8n cloud — "User Membership Sync" + "Ensure Contact in SF" | Handles `user_membership.*` events from WC Memberships. We rely on it for the post-acceptance enrichment. |
| Proxy (for ad-hoc / bulk queries) | github.com/nickatessense/wp-custom-plugin-cw-sf-bridge — separate repo `verdian-cw-abilities-proxy` | FastAPI on Vercel. Translates POST → GET-with-bracket-params for WC Abilities API. Used by `bulk-reconcile` flow (not built yet). |

## Open items / future work (PINs)

Items to address in v1.1+:

### 🔴 P1 — Names stay as "(Pending invitee)" in SF after acceptance

**Problem:** When an invitee accepts, `Ensure Contact in SF`'s "backfill by email" branch updates `Newspack_Member_User_ID__c`, `Company_Name_Text__c`, and `Active_Campaign_Email_Deliveribility__c` on the existing Contact — but **not** `FirstName` or `LastName`. So our pre-created Contact remains forever as "(Pending invitee)" even after the user is fully onboarded.

**Fix:** Add 2 fields to the PATCH body in `Update Contact (backfill Newspack ID)` node:
```json
{
  "FirstName": "{{ ... first_name }}",
  "LastName":  "{{ ... last_name }}"
}
```

**Risk:** Touches a production workflow. Test in staging first. Side effect: this also updates names on non-pending Contacts that happen to match by email (could overwrite manually-curated names — but the source-of-truth assumption is that WP wins).

### 🟡 P2 — Bulk reconciliation flow not built

**Problem:** If the live webhook ever misses an event (N8N downtime, plugin error, deploy gap), pending invitations created during that window never sync to SF.

**Plan:** Build a second N8N workflow `CW SF Bridge — Bulk Reconciliation` that runs weekly. It paginates through `/wc/v3/memberships/members` via the proxy, derives team_ids from `_team_id` meta, calls `invitations-list` per team, and pushes each pending through the same sub-workflow we built for the live flow.

**Why this is important:** without this, the integration has no recovery mechanism. Even one bad deploy could mean permanent data loss.

### 🟡 P3 — Cleanup of duplicate Newspack_Membership__c records after acceptance

**Problem:** As noted above, when an invitee accepts, the pending record stays and a separate active record is created. Two records per "logical membership" during transition.

**Options to address:**
- (a) Leave both (audit trail). Pending stays as `status="pending"`, doesn't pollute reports if filtered properly.
- (b) SF Flow that auto-merges/deletes pending when active is created. Trigger on `after-insert Newspack_Membership__c` where status=active. Cleanup logic stays in SF, no N8N touch.
- (c) Modify User Membership Sync to detect pendings first and update in place. More invasive.

Recommendation: **(a) for now, (b) when the data noise becomes a problem.** No urgency.

### 🟡 P4 — Invitation cancellations & expirations not tracked

**Problem:** When a team owner cancels a pending invitation, or the invitation expires, no event fires. Our SF pending record stays in `status="pending"` forever.

**Plan:** The bulk reconciliation flow (P2) can detect missing-from-CW records and update them to `status="cancelled"` or similar. Requires extending the bulk flow's logic with diff detection.

### 🟢 P5 — Contact already has membership at a DIFFERENT team

**Edge case:** Diego works at Verdian (has active membership in team X). Gets invited to consult at ComplianceWeek team Y. Currently we skip because his Contact already has a `Newspack_Membership__c` populated.

**Decision needed from business:** Should we still create a pending record for the new team, or is "this person already onboarded for some team, ignore" the right behavior? Today we do the latter. If you want the former, lift the "Contact has membership" check and let two memberships coexist (one active for team X, one pending for team Y).

### 🟢 P6 — Auto-create missing team Account in SF instead of skipping

**Currently:** If a team_id arrives that has no matching SF Account, the invitation is silently skipped (logged only).

**Could do:** Lookup team metadata via the proxy (we have `teams-get` ability for that), then auto-create the Account in SF. Removes the manual "make sure teams are loaded" precondition.

**Concern:** Auto-creating Accounts can cause data quality issues (duplicate Accounts, wrong Account hierarchies). Probably should stay manual unless business explicitly wants auto-create.

## How to debug

| Symptom | Where to look |
|---|---|
| Invitation created in CW, no webhook hit in N8N | (1) CW PHP `error_log` — search for `[CW-SF]` prefix. (2) Vercel logs of the plugin's POST attempt. (3) N8N webhook node — was it activated? |
| N8N receives event but no SF record created | N8N execution panel — open the failed execution, look for which node errored. Common: `Lookup Account by Team ID` returned 0 → check that the team's Account exists in SF |
| SF rejects POST/PATCH | Look at the failed HTTP node's response body — SF returns specific error codes (`REQUIRED_FIELD_MISSING`, `INVALID_FIELD`, etc.) |
| Duplicate Contact in SF | Either the email check has a typo, or the existing Contact was created with case-different email. SOQL is case-insensitive on Email by default but worth verifying. |
| Skipped events not visible anywhere | N8N execution panel shows all executions including skipped ones. Filter by workflow "CW SF Bridge — Pending Invitation to SF" and look for executions where the "Skipped: ..." nodes fired |

## Test plan checklist before going live

- [ ] Create test invitation in CW staging → confirm webhook arrives in N8N
- [ ] Webhook payload matches plugin output schema (see plugin README)
- [ ] Auth: send request without `X-API-Key` → expect 401
- [ ] Auth: send request with wrong `X-API-Key` → expect 401
- [ ] Skip case D: invite to a team that doesn't have an SF Account → expect skip + log
- [ ] Skip case C: invite an existing CW user with active membership → expect skip + log
- [ ] Happy case A: invite a brand-new email → expect new Contact + pending membership in SF
- [ ] Happy case B: invite an existing CW user without membership → expect no Contact modification + pending membership in SF
- [ ] Acceptance flow: invitee accepts → expect Newspack_Member_User_ID__c backfilled, separate active Newspack_Membership__c created
- [ ] Confirm `LastName` issue (P1 in open items) — verify if/when stakeholders want to fix

---

*Last updated: 2026-05-19*
