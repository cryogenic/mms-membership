# Changelog

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
