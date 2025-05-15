# Backup Module for HumHub
> [!WARNING]
> 🚧 This module is in beta and should not be used in full production till stated otherwise. 🚧

The **Backup Module** by Green Meteor provides automated backup functionality for HumHub installations. It allows site administrators to generate full backups of critical platform components including the database, `/protected/modules`, `/uploads`, `/themes`, and `/protected/config`.

## Features

- 🔁 Scheduled or manual backups through admin UI
- 🗃️ Backups may include: database, config, uploads, modules, and active theme
- 📦 Compressed ZIP output with a metadata manifest (`backup-info.json`)
- 🔧 Admin-configurable options with retention limits
- 🧹 Manual cleanup of older backups

## Usage
This module is intended to be installed like any other HumHub module. After enabling it from the admin panel, backups can be triggered manually or automatically.
