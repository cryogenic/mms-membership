# Changelog

## 1.0.0
- One login, many companies: a member account can now hold several company memberships. `member_user_id` is the source of truth; the legacy `mms_application_id` user meta stays as the default company.
- Member portal: company switcher (name + status chips), `?mms_company={application_id}` selection validated against ownership and remembered in `_mms_active_application`; every portal action (profile, password, certificate, invoice, receipt, payment, renewal) is scoped to the selected company.
- Admin company file: show all companies on an account, link/unlink an existing account (search by email, username or user ID), and warn when resetting a password shared across companies.
- Add company: account option None / Create new account / Attach to existing account; a PIC email that already has an account is attached automatically instead of being reported as an error.
- Existing accounts never receive new credentials: approvals and manual creation link the account, log `member_account_linked`, and email a new customisable template "Existing account — new company added" (company, reference, membership number, status, portal button). The approval email switches to "log in with your usual username and password" without a set-password link.
- Set-password links are only issued for newly created accounts.
- Project tooling: `.tools/backfill-member-links.php` links legacy `member_user_id = 0` applications from the `mms_application_id` user meta (dry-run supported).
- Security: ownership checks (`user_owns_application` / `resolve_application`) protect portal, certificate, invoice/receipt and payment endpoints against IDOR; regression and IDOR tests pass for single- and multi-company accounts.

## 0.22.2
- Allow more than one draft application: drafts now store `reference` as NULL (the UNIQUE index permits many NULLs) and receive the `MMS-YYYY-NNNN` reference when submitted. Adds an upgrade step that makes the `reference` column nullable and normalises existing empty values (DB version 0.12.0).
- Treat a missing reference as empty in every fallback label (`#id` / `APP-id`) across admin, payments, portal and resume pages.

## 0.22.1
- The username availability check now reports a member's own existing login as "the current username for this account" instead of "is available", so an already-registered username is never presented as free.

## 0.22.0
- Redesign the member dashboard with a premium layout that matches the login and set-password pages: member top bar (logo, account chip, log out), navy hero with company name, status pill, membership number and payment shortcut, stat tiles, and a two-column card grid with a sticky summary/account sidebar.
- Restyle payments, membership summary, company details, Person In Charge, documents (status pills), payment history (compact table) and the profile/password forms; tables follow the theme-neutral styling with labelled rows on mobile.
- Give the logged-out and "no membership linked" states the same split brand panel used on the member login page, with clear actions (member login / apply, back to site / log out).
- Add `assets/css/mms-portal.css` (loaded with `mms-login.css` on the portal only).

## 0.21.0
- Redesign the member set-password page with the same premium split layout as the login page: MACM brand panel with a 3-step progress list (application approved → set password → log in and pay) beside a white form panel.
- Add per-field Show/Hide password toggles and a live checklist (minimum length, passwords match) driven by `assets/js/mms-set-password.js`.
- Restyle the status panels (password saved, invalid/expired link) with success/error badges, member username and direct actions.
- Load `mms-login.css` and the new `assets/css/mms-set-password.css` only on the set-password page.

## 0.20.0
- Open the password reset form as a lightbox on the member login page, with Escape/backdrop close and inline success/error feedback.
- Add an AJAX reset endpoint (`mms_lost_password`) that only emails `mms_member` accounts, always returns the same neutral message (no account enumeration), and throttles per email (5 min) and per IP (1 min).
- Keep a no-JS fallback that posts to the core lost-password screen.
- Brand the core `wp-login.php` screens (login, lost password, reset password) with a MACM card layout, logo and palette via `assets/css/mms-login-screens.css`.

## 0.19.0
- Redesign the member login page with a premium split layout: MACM-branded panel (logo, membership benefits, secure-access note) beside a clean white sign-in card.
- Add a "New registration" button that links to the public membership registration page.
- Add a dedicated `assets/css/mms-login.css` stylesheet, scoped to the login shell, with responsive stacking on tablet and mobile.
- Add `MMS_Members::registration_url()` as the shared lookup for the registration page.

## 0.18.4
- Make the username and email of an existing member account editable from the Member account card.
- Check the new username live and exclude the account being edited, so an unchanged username is not reported as taken.
- Reject a username or email already used by another account, and record the change in the activity log.

## 0.18.3
- Check username availability live in the Create member account form, with available / already taken feedback.
- Block submission while the chosen username is taken and re-check after every edit.
- Return a specific error notice when a taken username reaches the server, instead of a generic failure.

## 0.18.2
- Add an editable Account email field to the Create member account form, prefilled from the Person In Charge email when available.
- Allow admins to create a member account for companies with no Person In Charge email by entering the account email manually.
- Keep the application's Person In Charge details unchanged when a different account email is used.

## 0.18.1
- Make the company name in the Company List link to the application detail screen.
- Show a Create member account form in the Member account card when a company has no linked login, using the Person In Charge email.
- Let admins set the username and password, create the account and email the login details in one step.

## 0.18.0
- Add a Member account section to the company detail screen showing the linked username and email.
- Let admins generate or type a new member password, save it and email the new login details to the member in one step.
- End any active member sessions after an admin password reset and record the change in the activity log.
- Add an editable "Member password — reset by admin" email template to Settings → Email notifications.

## 0.17.0
- Add an Activity Log screen under MMS → Log with every recorded application, document, payment, email and certificate event.
- Filter the log by activity type or search by company, reference, details or IP; paginate 30 events per page.
- Colour-coded activity pills and direct links from each event to the company detail screen.

## 0.16.1
- Rename the applications screen and its menu entry to "Company List".
- Rename the duplicate "MMS" submenu entry to "Dashboard".
- Add newest / oldest date sorting to the company list, kept across pagination and filters.

## 0.16.0
- Add a "Membership performance" dashboard band with a 12-month applications trend chart and monthly fees collected chart.
- Add a 90-day expiry forecast strip with overdue, 0–30, 31–60 and 61–90 day buckets linked to Renewals.
- Charts are server-rendered SVG (no external library) and data is cached for 10 minutes.

## 0.15.1
- Add search by company name, SSM/ROS number or membership number to the public directory.
- Paginate the member list at nine companies per page, keeping the search term across pages.
- Redesign the directory with a premium hero, search bar, company cards, status pills and styled pagination.

## 0.15.0
- Add a public member directory via the `[mms_directory]` shortcode.
- List active and expired members with company name, validity dates and Active/Expired status.
- Show company details (name, SSM/ROS number, validity, phone, address) on the same page when a company is clicked.

## 0.14.0
- Add username and temporary password fields when an admin creates a company manually.
- Check username availability as the admin types, and validate it again on save.
- Generate a random temporary password and email the login details to the Person In Charge.
- Add a customisable "Member account — login details" email template.

## 0.13.2
- Customisable approval and payment email templates (subject and WYSIWYG body) in Settings → Email notifications, with placeholders and safe HTML sanitising.
- Settings screen sections are grouped into six tabs with one sticky Save action, a remembered last tab and no-JavaScript fallback.

## 0.13.1
- Settings screen sections are grouped into tabs with a single Save action, instead of one long page.

## 0.13.0
- Customisable approval and payment email templates (subject and WYSIWYG body) in Settings → Email notifications, with placeholders.
- Keep the branded layout, application details and action buttons under plugin control; blank fields fall back to the default wording.
- Sanitise custom HTML with `wp_kses_post` and escape all placeholder values.

## 0.12.1
- Release notes in the Plugins update screen are now generated from this changelog.
- Release workflow can update an existing GitHub release instead of failing on re-runs.

## 0.12.0
- Add GitHub release updates through Plugin Update Checker v6: standard "Update Now" in Plugins, release notes as changelog.
- Separate `MMS_DB_VERSION` from the plugin version; schema upgrades run on `plugins_loaded` with a transient lock, with `admin_init` as fallback.
- Disable WordPress auto-updates for this plugin so schema migrations only run when an admin updates manually.
- Support private repositories through the `MMS_GITHUB_TOKEN` constant (never stored in the plugin or database).
- Add release workflow that builds `mms-membership-{version}.zip` with `vendor/` included.

## 0.11.0
- Generate invoice PDFs at approval and receipt PDFs after payment, downloadable by members and admins.
- Record manual admin payments (amount, date, channel, reference) and activate membership through the existing flow.
- Add invoice & receipt logo upload in Settings (with bundled MACM logo fallback).
- Store payment period start/end on the payment row.

## 0.10.0
- Certificate numbering per membership type and state (`TYPE/MEMBERSHIP/STATE/SERIAL`).
- Add State field to public registration and manual company creation.

## 0.9.2
- Bulk actions on the application list (mark under review, approve with payment pending).

## 0.9.1
- Premium styling for Applications, Renewals and Settings screens.

## 0.9.0
- Premium admin dashboard with kanban, redesigned member portal, admin certificate download and manual company creation.

## 0.8.0
- Renewals and expiry: daily cron, reminders at 30/14/7/0 days, renewal payments and Renewals report.

## 0.7.1
- Membership certificate uses the MACM certificate template via FPDI.

## 0.7.0
- ToyyibPay payments, automatic activation, receipt email and certificate PDF.

## 0.6.0
- Member login, portal, profile and password forms.

## 0.5.0
- Member accounts at approval and set-password links.

## 0.4.0
- Admin review panel, decisions, need-info resume links and bulk approval.

## 0.3.0
- Public multi-step registration form and document upload.

## 0.2.0
- Membership types, document catalogue and Settings screen.

## 0.1.0
- Initial plugin structure, database tables, roles and capabilities.
