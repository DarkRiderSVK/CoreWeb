# CoreNetwork Web Portal

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.1+">
  <img src="https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL">
  <img src="https://img.shields.io/badge/Minecraft-1.13%20--%201.21%2B-2E7D32?style=for-the-badge&logo=minecraft&logoColor=white" alt="Minecraft">
  <img src="https://img.shields.io/badge/Platform-Paper%20%7C%20Purpur%20%7C%20Forge%20%7C%20NeoForge-orange?style=for-the-badge" alt="Platform">
  <img src="https://img.shields.io/badge/Languages-EN%20%7C%20SK%20%7C%20CZ%20%7C%20IT%20%7C%20FR-blue?style=for-the-badge" alt="Languages">
  <img src="https://img.shields.io/badge/License-GNU%20LGPL--3.0-blue?style=for-the-badge" alt="License">
</p>

---

> 🌐 **Live Production Demo:**  
> Experience a fully configured, production-ready deployment of the portal in real time at [**https://zombieapocalypse.sk/**](https://zombieapocalypse.sk/).

**CoreNetwork Web Portal** is an open-source, modular web ecosystem engineered specifically for Minecraft multi-server networks operating on the **CoreSync** backend. It bridges your Minecraft servers directly to players' browsers via a unified MySQL/MariaDB database—eliminating the need for custom web sockets or complex middleware proxies.

---

## 📑 Table of Contents
- [Core Features](#-core-features)
- [Modded Ecosystem & Custom Asset Pipeline](#-modded-ecosystem--custom-asset-pipeline)
- [Administration & Telemetry Suite](#-administration--telemetry-suite)
- [Directory Structure](#-directory-structure)
- [Installation & First Run](#-installation--first-run)
- [System Requirements](#-system-requirements)
- [License](#-license)

---

## 🌟 Core Features

### 🖥️ Interactive Server Selector & Live Telemetry
- **Dynamic Network Cards:** Continuously polls and renders sub-server status, current TPS, RAM consumption, and player capacity.
- **Orbital Day/Night Celestial Simulation:** Computes the exact in-game tick cycle (`world_time % 24000`) on each server, rotating animated Sun and Moon celestial bodies along orbital trajectories.
- **Dynamic Weather Engine:** Renders animated rainfall particle effects across the card whenever downpours or thunderstorms occur on the target world.
- **Interactive Player Rosters:** Real-time online head avatars with hover tooltips linking directly to individual gameplay dossiers.

### 🛒 Global WebShop & Anti-Dupe Vault
- **Dual Market Model:** Features a server-administered **Admin Shop** alongside a decentralized **Player Marketplace**.
- **Barter & Currency Trading:** Supports trades in standard in-game economy funds or custom material exchanges (barter trades).
- **Multi-Item Bundles:** Allows bundling full armor kits, weapons, and resources under a unified listing price.
- **Anti-Dupe Security Lock:** Automatically switches player web actions into a read-only lock whenever the player's in-game vault GUI (`/webshop`) is active.
- **Stack & Exploit Protections:** Restricts listing stack ceilings based on item classification (e.g., weapons/armor capped at 1, potions/ender pearls capped at 16) and caps active player listings via `max_listings_per_player`.

### 💬 Bi-Directional WebChat & Dynmap Integration
- **Two-Way Message Relay:** Streams messages between web browsers and Minecraft sub-servers with full translation of color codes (`&` and `§`).
- **Global Network Broadcasts:** Allows authorized players to toggle between local sub-server chat and the network-wide global channel (`[G]`).
- **Live Map Splitting:** Side-by-side split viewport embedding live Dynmap / Pl3xMap iframe viewports.
- **Spam & Moderation Defenses:** Enforces an active message cooldown timer, minimum in-game playtime thresholds, and automatic suppression of players with active bans, mutes, or jail records.

### 🏆 Quests & Progression (CoreRewards)
- **Journey Leveling Architecture:** Players earn XP by completing objectives, gaining one web Journey Level every 900 XP.
- **Multi-Vector Goals:** Tracks blocks mined, blocks placed, mob kills, player kills, custom mod events, advancements, and votes cast.
- **Asynchronous Claim Delivery:** Web claims are processed transactionally and staged in `coresync_rewards` with server-specific targeting for execution.

### 🗳️ Vote Auditing & Incentives (CoreVotifier)
- **Automated Player URL Tagging:** Automatically injects the player's username into external voting list parameters via `{user}`.
- **Podium Ranking:** Highlights top voters on Gold, Silver, and Bronze pedestals alongside comprehensive monthly leaderboards.
- **Animated Showcases:** Dynamic visual reward cards displaying potential drops with in-game tooltips.

### 📊 Deep Player Profiles & Global Banlist
- **Comprehensive Player Dossiers:** Aggregates playtime ticks, jumps, PvP stats, distance traveled (walked/flown), Lootr chests opened, and villager trades.
- **Itemized Leaderboards:** Sorted tallies for top mined blocks, crafted items, broken tools, and player nemeses.
- **Global Punishment Audit:** Transparent, searchable banlist tracking permanent and temporary bans, IP bans, mutes, jails, kicks, and warnings.

---

## 🧩 Modded Ecosystem & Custom Asset Pipeline

CoreNetwork Web Portal is natively compatible with heavily modded environments (Forge, NeoForge):

- **Custom Icon Storage (`assets/icons/`):**
  - Drop any modded item, machine, or block texture directly into `assets/icons/` as PNG files.
  - Uploaded textures are indexed and searchable within the admin reward, quest, and webshop icon selection modals.
- **Forge NBT & Namespace Parser:**
  - Handles custom namespace keys (e.g., `the_vault:`, `ironchest:`, `thermal:`) alongside vanilla Minecraft IDs.
  - Parses Forge NBT strings, formatting custom names, lore lines, and enchantments in authentic pixel-styled Minecraft tooltip containers.
- **Automated Discovery Engine (Autocomplete Sync):**
  - Includes `admin/ajax_generate_stats.php`, which scans real player data within the MySQL database with one click.
  - Extracts newly discovered modded items, blocks, and entities into a cached `cfg/journey_stats_[server].json` file, auto-populating suggestions without requiring manual dictionary setup.

---

## 🛠️ Administration & Telemetry Suite

Protected by a fine-grained, node-based permission system stored in `coresync_admins`:

- **Real-Time Performance Dashboard:**
  - Chart.js graphs tracking TPS curves, player concurrency, and memory consumption across all servers.
  - Telemetry filters spanning 24-hour, 7-day, and 30-day monitoring intervals.
  - Voting analytics charting daily velocity and 7-day trailing averages.
- **Per-Server Web Module Management:**
  - Control individual Web Add-on toggles to independently enable or disable features for CoreBans, CoreRewards, CoreWebChat, CoreVotifier, and CoreWebshop on specific servers.
- **Custom Content CMS:**
  - WYSIWYG editor (TinyMCE) for authoring articles, server rules, guides, and download hubs with slug control and navigation ordering.
- **Portal & Server Settings:**
  - Configure branding, custom logo dimensions, timezones, hero banners, and footer credits.
  - Customize server cards with hex pickers, connection instructions, modpack download URLs, and minimum playtime restrictions for chat and comments.

---

## 📁 Directory Structure

```text
├── admin/                     # Administration suite & analytics
│   ├── ajax_generate_stats.php# Modded telemetry scanner & autocomplete generator
│   ├── index.php              # Real-time Chart.js telemetry dashboard
│   ├── pages.php              # Custom page CMS with TinyMCE
│   ├── plugins.php            # Server plugin overview & Web Add-on toggles
│   ├── settings.php           # Global portal & sub-server configuration
│   └── voting.php             # Voting site & reward item manager
├── assets/                    # Static styling, scripts, and media
│   └── icons/                 # Directory for modded & vanilla item PNG textures
├── cfg/                       # Protected database & cached statistics configs
├── includes/                  # Core procedural libraries & helpers
│   ├── auth.php               # Session security, auth hooks & XSS guards
│   ├── db.php                 # PDO database connector
│   ├── icon_helper.php        # Minecraft tooltip renderer & texture resolver
│   └── lang/                  # Localization dictionaries (en, sk, cz, it, fr)
├── ajax_claim.php             # Quest claim processor & reward command dispatcher
├── ajax_status.php            # Real-time JSON telemetry endpoint
├── ajax_webshop.php           # Transaction queue handler & vault manager
├── bans.php                   # Global banlist
├── index.php                  # Primary portal landing page & server summary
├── install.php                # 5-step multilingual installation wizard
├── profile.php                # Player profile dossier & personal records
├── rewards.php                # Player quest journey & reward catalog
├── selector.php               # Standalone multi-server selector landing page
├── stats.php                  # Paginated server leaderboard & player search
├── vote.php                   # Voting hub with top-voter podium
├── webchat.php                # Live chat bridge & map viewport
└── webshop.php                # Market showcase, bundle creator & vault interface
```

---

## 🚀 Installation & First Run

Installation is handled via the built-in wizard `install.php`.

> ⚠️ **CRITICAL SETUP PREREQUISITE:**  
> Run `install.php` in your browser **ONLY AFTER CoreSync has launched successfully at least once on your Minecraft server**.  
> CoreSync initializes the required database schema on its first boot, which the web wizard requires to detect your servers and populate initial network tables.

### Step-by-Step Procedure:

1. **Deploy Files:** Upload all portal files to your web server's document root (e.g., `/var/www/html` or `public_html`).
2. **Access Setup Wizard:** Open your browser and navigate to:
   ```text
   [https://yourdomain.com/install.php](https://yourdomain.com/install.php)
   ```
3. **Complete the 5 Setup Steps:**
   - **Step 1 (Language):** Select your installation language (EN, SK, CZ, IT, FR).
   - **Step 2 (Database):** Input the MySQL/MariaDB credentials used by CoreSync on your Minecraft server.
   - **Step 3 (Main Administrator):** Provide the exact in-game username of the player who will receive root administrative privileges.
   - **Step 4 (Portal Content):** Define your network branding, header titles, and footer copy.
   - **Step 5 (Server Configuration):** The wizard automatically detects all servers registered in the database, allowing you to configure the Server Selector, display names, and theme colors.
4. **Final Security Measure:**
   - The wizard compiles your database configuration into `cfg/db.php`.
   - **Delete `install.php` immediately after installation to protect your setup**.

---

## 📋 System Requirements

- **Web Server:** Apache (with `mod_rewrite` enabled), Nginx, or LiteSpeed
- **PHP:** Version 8.1 or higher
- **Required PHP Extensions:**
  - `pdo_mysql` (Database pooling and operations)
  - `json` (Parsing Forge NBT, player stats, and inventory structures)
  - `session` (Session management with 7-day persistence and anti-XSS flags)
  - `curl` or `allow_url_fopen` (Resolving skins and player avatars)
- **Database:** MySQL 5.7+ or MariaDB 10.3+
- **Minecraft Server Backend:**
  - **CoreSync** (Mandatory core plugin/mod backend)
  - Optional Modules: **CoreWebChat**, **CoreWebshop**, **CoreRewards**, **CoreVotifier**, **CoreBans**

---

## 📄 License

This project is licensed under the **GNU Lesser General Public License v3.0 (LGPL-3.0)**.
