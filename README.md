# WEAMMS

## Configuration

Set these environment variables in production. Do not commit `.env` files,
production credentials, or database backups.
The application reads process/server environment variables; copying
`.env.example` to `.env` does not load it automatically.

```text
WEAMMS_ENV=production
WEAMMS_DB_HOST=localhost
WEAMMS_DB_NAME=wis_event_attendance
WEAMMS_DB_USER=wis_app
WEAMMS_DB_PASSWORD=use-a-strong-secret
WEAMMS_TIMEZONE=Asia/Manila
```

Use a dedicated database account with only the permissions the app requires.
The application rejects the development `root`/empty-password defaults when
`WEAMMS_ENV=production`, which is the default when `WEAMMS_ENV` is omitted.
Set `WEAMMS_ENV=development` explicitly for a local XAMPP installation that
uses the local `root`/empty-password defaults. Session cookies are HTTP-only
and SameSite=Lax; the Secure flag is enabled for HTTPS requests and always in
production. Login failures are limited per source IP and per username/IP pair
over a 15-minute window (five failures for a username/IP pair; 30 per IP).

## Installation and upgrades

For a new installation, import `database/wis_event_attendance.sql`. It creates
the current schema without sample accounts or records. `database/demo_seed.sql`
is for disposable local/testing databases only; never import it into production.
The schema includes only the built-in role definitions. Set
`WEAMMS_INITIAL_ADMIN_USERNAME`, `WEAMMS_INITIAL_ADMIN_EMAIL`, and
`WEAMMS_INITIAL_ADMIN_PASSWORD` in the CLI environment, then run
`php <application-path>\scripts\create-admin.php` once to create the first
administrator. The command refuses to run once any administrator exists.
Remove those environment variables immediately afterward.

For an existing installation, take a backup, then apply the migrations in
chronological order that have not already been applied. The latest migrations
are:

1. `database/migrations/20261030_attendance_integrity.sql`
2. `database/migrations/20261031_role_auth_login_limits.sql`

Deploy the PHP changes after migrations succeed. To run the demo locally, import
the schema first and then optionally import `database/demo_seed.sql`.

## Time and event finalization

`WEAMMS_TIMEZONE` accepts `Asia/Manila` (default) or `UTC`. PHP and each MySQL
connection use the same setting, so event cutoffs and displayed times agree.
Choose one setting before importing/migrating data; changing it later requires
a deliberate conversion of existing datetime values.

Event statuses and no-show absences are also synchronized during protected
requests. To finalize events even when no one is using the site, schedule
`php <application-path>\scripts\finalize-events.php` every five minutes using
Windows Task Scheduler or the host's equivalent scheduler.

## Backup and restore

Back up the database and the private `uploads/student-appeals` directory
together. Example database backup and restore commands:

```text
mysqldump --single-transaction -h localhost -u wis_app -p wis_event_attendance > backup.sql
mysql -h localhost -u wis_app -p wis_event_attendance < backup.sql
```

Keep backups outside the web document root, restrict access to them, and test
restores periodically. Restore the matching `uploads/student-appeals` backup
with the database backup.

## Unimplemented modules

`admin/academic-periods/*` is a placeholder for a planned module. They return HTTP 501 rather than a blank
success response, and throw an explicit exception if another file tries to
include them. It is linked from the administrator sidebar. The administrator Security page
(`admin/security/index.php`) is implemented as a read-only view of sign-in
protection rules, locked/inactive accounts, and the administrator activity log.
