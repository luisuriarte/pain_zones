# 🩺 OpenEMR Pain Zones Clinical Encounter Form (`pain_zones`)

[![OpenEMR Compatible](https://img.shields.io/badge/OpenEMR-v7.0%20--%20v8.4%2B-blue.svg?logo=circle&logoColor=white)](https://www.open-emr.org)
[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![Database](https://img.shields.io/badge/Database-MySQL%20%7C%20MariaDB-4479A1.svg?logo=mysql&logoColor=white)](#-database-schema)
[![UI](https://img.shields.io/badge/UI-Bootstrap%204.6.2-7952B3.svg?logo=bootstrap&logoColor=white)](#-environment--browser-compatibility)
[![Graphics](https://img.shields.io/badge/Graphics-SVG%201.1%20Vector-FFB13B.svg?logo=w3c&logoColor=white)](#-key-clinical-features)
[![Standard](https://img.shields.io/badge/Clinical%20Standard-NRS%200--10-orange.svg?logo=heart&logoColor=white)](#-clinical-pain-scale--severity-bands)
[![Security](https://img.shields.io/badge/Security-CSRF%20%2B%20ACL%20Guarded-success.svg?logo=shield&logoColor=white)](#-architecture--security)
[![License](https://img.shields.io/badge/License-GPLv3-green.svg?logo=gnu&logoColor=white)](https://www.gnu.org/licenses/gpl-3.0.html)

A hospital-grade, interactive anatomical vector mapping encounter form designed for enterprise clinical workflows, inpatient units, outpatient specialty clinics, physical therapy, and multidisciplinary pain management centers within **OpenEMR**.

The **Pain Zones** module empowers clinicians to accurately document, visualize, and monitor patient-reported pain across adult male and female anatomical diagrams (Anterior & Posterior views). Each mark captures high-precision vector coordinates, validated anatomical regions, numeric pain severity (0–10 NRS), clinical pain descriptors, radicular radiation, onset, modulating factors, and granular clinical notes.

---

## 📑 Table of Contents

- [✨ Key Clinical Features](#-key-clinical-features)
- [🔒 Architecture & Security](#-architecture--security)
- [🗄️ Database Schema](#-database-schema)
- [📊 Clinical Pain Scale & Severity Bands](#-clinical-pain-scale--severity-bands)
- [📁 Directory Structure](#-directory-structure)
- [🚀 Installation & Setup](#-installation--setup)
- [🩺 Clinical Workflow & Usage](#-clinical-workflow--usage)
- [🖨️ Reporting & PDF Engine Integration](#-reporting--pdf-engine-integration)
- [🛡️ Permissions & Access Control (ACL)](#-permissions--access-control-acl)
- [💻 Environment & Browser Compatibility](#-environment--browser-compatibility)
- [📜 License](#-license)

---

## ✨ Key Clinical Features

- 📍 **Interactive SVG Anatomical Mapping:**
  - 🧍‍♂️ **Bilateral & Dual-Gender Diagrams:** Scalable vector graphics representing Adult Male and Adult Female anatomy in Anterior and Posterior orientations.
  - 📐 **Sub-Pixel Coordinate Inversion:** Coordinate projection matrix (`getScreenCTM().inverse()`) computes sub-pixel percentage positions (`x_pct`, `y_pct` between `0.000%` and `100.000%`) invariant to display DPI, responsive scaling, or viewport orientation.
  - 🛡️ **Anatomical Whitelist:** Strict anatomical whitelist validation covering primary anatomical regions (`head`, `neck`, `chest`, `abdomen`, `pelvis`, `spine`, `shoulder`, extremities, `genitals`, `perineum`).
- 🎨 **Dynamic Anatomical Zone Tinting:**
  - 🌈 **Peak Severity Tint:** Anatomical regions automatically tint according to the peak reported pain severity (`fill-opacity: ~0.45` and perimeter stroke colored by level).
  - ⚪ **Asymptomatic Baseline:** Intensity `0` marks (pain resolved / asymptomatic) render as neutral grey pins without obscuring tissue boundaries.
  - 🏷️ **Numbered Badges:** High-contrast intensity badges render directly above vector landmarks with soft drop-shadows.
- 📋 **Comprehensive Pain Assessment Protocol:**
  - 🔢 **Intensity:** 0–10 Numeric Rating Scale (NRS).
  - ↔️ **Laterality:** Server-derived (`left`, `right`, or `midline`) directly from validated anatomical IDs.
  - ⚡ **Pain Quality:** Sharp, dull, throbbing, burning, stabbing, aching, electric, colicky, etc.
  - 🎯 **Radiation:** Radicular projections or dermatomal spread.
  - ⏱️ **Temporal Patterns:** Precise onset (e.g., *3 days post-trauma*) and duration patterns (e.g., *intermittent, 2-hour episodes*).
  - ⚖️ **Modulating Factors:** Aggravating mechanics and relieving interventions.
  - 📝 **Clinical Notes:** Granular per-zone documentation plus general encounter consultation notes.
- 🌐 **Global Cross-Panel Sidebar:**
  - 📑 **Unified Case Roster:** Cataloging all patient pain marks across all 4 anatomical panels, tagged with clear orientation badges (e.g., `Female / Posterior`).
  - 🔄 **Instant Focus & Synchronization:** Clicking any item automatically synchronizes radio selectors, transitions the SVG diagram, refreshes live counters, and opens the editor.
- 📄 **Hospital Charting & PDF Integration:**
  - 🖨️ **Zero-Dependency Renderer:** Server-side rendered print and PDF report generator (`report.php`).
  - 📋 **Clean Medical Tabular Layout:** Native tabular layout optimized for electronic health record exports and paper charts.

---

## 🔒 Architecture & Security

Engineered following OpenEMR enterprise security standards and strict data governance protocols:

- 🧱 **Strict Type Enforcement:** `declare(strict_types=1);` implemented on all backend endpoints.
- 🛡️ **Session & Patient Isolation:**
  - 🆔 **Patient & Encounter Verification:** Active session validation using `PatientSessionUtil::getPid()` and `EncounterSessionUtil::getEncounter()`.
  - 🚫 **Cross-Encounter Protection:** Multi-tenant hijacking prevention via `EncounterFormAccess::assertFormBelongsToSessionPatient($formId, 'pain_zones')`.
- 🔑 **CSRF Defense:**
  - 🎫 **Cryptographic Tokens:** Form tokens generated via `CsrfUtils::collectCsrfToken(session: $session)` (`subject: 'default'`).
  - 🛑 **Strict POST Validation:** Verified server-side via `CsrfUtils::checkCsrfInput(INPUT_POST)`, returning an explicit HTTP 403 on validation failure.
- 👥 **Role-Based Access Control (ACL):**
  - 📖 **Read Authorization:** Required through `pz_requireRead()` against `patients/med`.
  - ✍️ **Write Authorization:** Verified via `pz_canWrite('new')` and `pz_canWrite('update')`.
  - 👁️ **Read-Only Mode:** Unauthorized or auditing users can freely inspect anatomical vector maps, review pin distributions, and view clinical dialogues while editing and deletion controls are completely locked.
- 🧼 **Sanitization & Transaction Safety:**
  - 🧹 **Text Sanitization:** String sanitization cleans non-printable control characters (`\x00-\x08`, `\x0B-\x0C`, `\x0E-\x1F`, `\x7F`) while preserving legitimate multiline documentation (`pz_sanitizeText`).
  - ⚛️ **Atomic Transactions:** Atomic database transactions (`QueryUtils::inTransaction()`) guarantee that headers, mark insertions, modifications, and soft-deletes succeed as an indivisible unit.
  - 📜 **Immutable Audit Trail:** Recorded in `form_pain_zones_history` storing comprehensive JSON snapshots for medico-legal compliance.

---

## 🗄️ Database Schema

The module provisions three relational tables and populates OpenEMR's `list_options`:

1. 📑 **`form_pain_zones` (Form Header):**
   - 🔑 Core encounter header binding `id`, `date`, `pid`, `user`, `groupname`, `authorized`, and `activity`.
   - 📝 Stores `general_notes` (up to 5,000 characters) and `summary_text` for clinical summarization.
2. 📍 **`form_pain_zones_marks` (Pain Coordinates & Clinical Observations):**
   - 🔗 Stores individual pain entries linked to `form_id` and `pid`.
   - 🗂️ Fields: `figure`, `body_view`, `zone_id`, `laterality`, `x_pct`, `y_pct`, `intensity` (0–10), `pain_quality`, `radiation`, `onset`, `duration`, `aggravating`, `relieving`, `notes`, and active status.
3. 📜 **`form_pain_zones_history` (Audit Trail):**
   - ⏱️ Complete historical tracking of `insert`, `update`, and `delete` operations with timestamps, authorizing clinician, and complete JSON snapshots.
4. 🎨 **`list_options` (`pain_zones_level`):**
   - 🏷️ 11 preconfigured NRS levels (`level_0` to `level_10`) with hex color definitions (`stroke` and `fill`) and severity bands (`none`, `mild`, `moderate`, `severe`).

---

## 📊 Clinical Pain Scale & Severity Bands

| 🔢 NRS Level | 🏷️ Severity Band | 🎨 Color Palette | 🖌️ Hex (Stroke / Fill) | 📋 Clinical Significance |
|:---:|:---:|:---:|:---:|:---|
| **0** | **None** | ⚪ Grey | `#848484` / `#BDBDBD` | 🟢 No pain / baseline resolved state |
| **1** | **Mild** | 🔵 Blue | `#1565C0` / `#42A5F5` | 🔹 Very mild, barely noticeable |
| **2** | **Mild** | 🔵 Sky Blue | `#0277BD` / `#29B6F6` | 🔹 Minor pain, does not interfere with daily activities |
| **3** | **Mild** | 🟢 Teal | `#00695C` / `#26A69A` | 🔹 Noticeable pain, manageable without intervention |
| **4** | **Moderate** | 🟢 Green | `#2E7D32` / `#66BB6A` | 🔸 Moderate pain, begins to interfere with concentration |
| **5** | **Moderate** | 🟡 Lime | `#558B2F` / `#D4E157` | 🔸 Distracting pain, limits some routine tasks |
| **6** | **Moderate** | 🟡 Amber | `#F9A825` / `#FFEE58` | 🔸 Substantial pain, significantly impacts daily living |
| **7** | **Severe** | 🟠 Orange | `#E65100` / `#FFA726` | 🔺 Severe pain, dominates senses, impedes movement |
| **8** | **Severe** | 🔴 Deep Orange | `#BF360C` / `#FF7043` | 🔺 Intense pain, severe functional impairment |
| **9** | **Severe** | 🔴 Crimson | `#B71C1C` / `#EF5350` | 🚨 Excruciating pain, patient unable to converse or stand |
| **10** | **Severe** | 🟣 Purple | `#4A148C` / `#AB47BC` | ⚡ Worst pain imaginable, requires immediate acute intervention |

> [!TIP]
> 🎨 **Customization Tip:** Palette colors and titles can be adjusted at any time in OpenEMR under **Administration > Lists > Pain Zones Level**.

---

## 📁 Directory Structure

```text
interface/forms/pain_zones/
├── 📂 assets/
│   ├── 🧍‍♀️ female_anterior.svg   # 72 validated anatomical regions
│   ├── 🧍‍♀️ female_posterior.svg  # 58 validated anatomical regions
│   ├── 🧍‍♂️ male_anterior.svg     # 74 validated anatomical regions
│   └── 🧍‍♂️ male_posterior.svg    # 58 validated anatomical regions
├── ⚙️ pain_zones.inc.php        # Core backend library, SVG parsers, ACL guards, validator
├── 🖥️ new.php                   # Interactive clinical UI (creation, review & read-only modes)
├── 💾 save.php                  # Atomic transaction endpoint with structured JSON responses
├── 🔀 view.php                  # Guarded router redirecting to new.php?id=N
├── 🖨️ report.php                # Print and PDF summary report renderer
├── 🗃️ table.sql                 # Database table definitions and list_options seed
├── 🌐 lang_custom.sql            # Spanish (Latin American Hospital) translations and sync
└── 📄 info.txt                  # OpenEMR form descriptor (pain_zones)
```

---

## 🚀 Installation & Setup

### 1️⃣ 📁 Place Module Files
Copy the `pain_zones` directory into your OpenEMR installation forms directory:
```bash
cp -r pain_zones /var/www/localhost/htdocs/openemr/interface/forms/
```

### 2️⃣ 🗄️ Run Database Migration
Execute `table.sql` on your target OpenEMR database:
```bash
mysql -u openemr -p openemr < /var/www/localhost/htdocs/openemr/interface/forms/pain_zones/table.sql
```

### 3️⃣ 🌐 Import Spanish Translations (Optional)
To load complete Spanish (Latin American Hospital) clinical translations:
```bash
mysql -u openemr -p openemr < /var/www/localhost/htdocs/openemr/interface/forms/pain_zones/lang_custom.sql
```

### 4️⃣ ⚙️ Register in OpenEMR Administration
1. 🔐 Log in to OpenEMR with an **Administrator** profile.
2. 🧭 Navigate to **Administration > Forms > Forms Administration**.
3. 🔎 Locate **Pain Zones (`pain_zones`)** under **Unregistered Forms**.
4. 📝 Click **Register**.
5. 🗄️ Click **Install DB**.
6. 🏷️ Set **Nickname** to `Pain Zones` and toggle status to **Active** (🟢).
7. 🎉 Clinicians will now find **Pain Zones** ready under the encounter **Clinical Forms** menu.

---

## 🩺 Clinical Workflow & Usage

1. 📂 **Initiating an Assessment:**
   - 🩺 From an active clinical encounter, select **Clinical Forms > Pain Zones**.
   - 🔄 The view defaults to the panel with the highest number of reported marks (or *Adult Male Anterior* on clean forms).
2. 📍 **Documenting Pain Marks:**
   - 👆 Click on any anatomical zone on the diagram.
   - 💬 The Clinical Assessment Dialog opens:
     - 🎚️ Adjust intensity (0–10) using the slider; the color reflects live palette changes.
     - 📝 Record pain quality, radicular radiation, onset, and modulating factors.
     - ✅ Click **Confirm** to cache the entry in local memory.
   - 🎨 The zone automatically tints according to severity and places a high-contrast pin at the marked location.
3. ✏️ **Modifying or Removing Marks:**
   - 🎯 Click on any pin directly on the body diagram or click **Edit** (✏️) from the lateral list.
   - 🔄 Update parameters or click **Delete Mark** (🗑️) to remove the point.
4. 💾 **Saving the Encounter:**
   - 📋 Add overarching impressions into **General Notes**.
   - 💾 Click **Save**. Data submits asynchronously via `fetch`, persists within an atomic transaction, and closes the tab cleanly.

---

## 🖨️ Reporting & PDF Engine Integration

The module includes full reporting support via `pain_zones_report()` in `report.php`:
- 📄 Compatible with OpenEMR's **Patient Report**, **Encounter Summary**, and **PDF Print Engine**.
- 📊 Formats an elegant, structured table displaying:
  - 🗺️ Anatomical zone name (formatted with uppercase spacing).
  - ↔️ Laterality, figure, and view.
  - 🔢 NRS Intensity Score and Severity Band.
  - 📝 Clinical details (quality, radiation, timeline, aggravating/relieving factors, and notes) combined on an underlying line to prevent table overflow.
  - 📋 Form General Notes displayed at the base of the report.

---

## 🛡️ Permissions & Access Control (ACL)

| 🩺 Clinical Role | 👁️ Read Access (`patients/med`) | ✏️ Write Access (`patients/med`) | 🎯 Experience in Pain Zones |
|:---|:---:|:---:|:---|
| 👨‍⚕️ **Attending Physician / Specialist** | ✅ Yes | ✅ Yes (`write`) | 🟢 Full authoring, mark editing, deletion, and saving |
| 👩‍⚕️ **Resident / Clinical Nurse** | ✅ Yes | ✅ Yes (`write`/`addonly`) | 🟢 Full mark creation and charting capabilities |
| 🔍 **Auditor / Billing / Read-Only Staff** | ✅ Yes | ❌ No | 🟡 **Read-Only Mode**: Can inspect vector diagrams, navigate views, and click pins to review findings. Save, edit, and delete buttons are hidden |
| 🚫 **Unauthorized Account** | ❌ No | ❌ No | 🔴 Access Denied HTTP 403 via `AccessDeniedHelper` |

---

## 💻 Environment & Browser Compatibility

- 🐘 **PHP Compatibility:** PHP 8.2, 8.3, and 8.4 (`strict_types=1` enforced).
- 🗄️ **Database Engines:** MySQL 5.7+ / 8.0+ or MariaDB 10.4+.
- 🎨 **Frontend Architecture:** Vanilla ES6+ JavaScript, SVG 1.1 DOM, Bootstrap 4.6.2 (OpenEMR standard).
- 🌐 **Supported Browsers:**
  - 🌐 Google Chrome (v90+)
  - 💻 Microsoft Edge (v90+)
  - 🦊 Mozilla Firefox (v88+)
  - 🧭 Apple Safari (v14+)
- 📱 **Hardware Platforms:**
  - 🖥️ Clinical workstations & dual-monitor diagnostic setups.
  - 💻 Medical laptops & mobile carts (COWs/WOWs).
  - 📱 Medical tablets & touch displays (iPadOS, Windows Surface, Android).

---

## 📜 License

Distributed under the **GNU General Public License v3 (GPL-3.0)**. OpenEMR is a registered trademark of the OpenEMR Foundation.
