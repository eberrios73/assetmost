# AssetMost-River

This is AssetMost adapted to run against the LIVE River MariaDB on ITRack
(root@your-server, app at /var/www/AssetMost). The live database is the source
of truth — migrations cannot fresh-install here, and the schema quirks are
deliberate (legacy PKs loginID/vendorID/deviceID, plaintext login_pass, tables
named `subscriptions` and `it_tasks`) because the legacy ITer app reads the same
database. Do not "fix" any of that toward standard Laravel.

Never push this repo to any public remote. It is the private internal fork of
github.com/eberrios73/assetmost — if a fix belongs in the public product, it gets
re-applied there separately (schema mapping: git log -p BRANCH-MAP.md).

Runtime secrets live in /var/www/AssetMost/.env on the box, never in git.
