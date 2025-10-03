Professional Development Plugin
===============================

Overview
- WordPress admin plugin for tracking professional development (members, sessions, presenters) with optional external DB integration.

Uninstall Behavior
- On uninstall (Plugins → Delete), the plugin removes its stored options:
  - `ProfessionalDevelopment_db_host`
  - `ProfessionalDevelopment_db_name`
  - `ProfessionalDevelopment_db_user`
  - `ProfessionalDevelopment_db_pass`
  - `ProfessionalDevelopment_DB_password`
- Multisite: the same keys are also deleted from site options if they exist.
- Deactivation/activation does NOT remove data. Only a true uninstall triggers cleanup.

Notes
- Admin AJAX endpoints require a valid nonce and capability; front-end requests without a nonce or proper permissions are rejected.
- Encryption keys are derived per-site from WordPress salts and the site URL by default. If defined, `PS_ENCRYPTION_KEY` is used instead.
