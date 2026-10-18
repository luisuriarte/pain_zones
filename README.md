# 🩺 Pain Zones Encounter Form for OpenEMR (`pain_zones`)

[![Tested on OpenEMR](https://img.shields.io/badge/OpenEMR-8.2.0%20%7C%208.4.1%20(Tested)-blue.svg?logo=circle&logoColor=white)](https://www.open-emr.org)
[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![Database](https://img.shields.io/badge/Database-MySQL%20%7C%20MariaDB-4479A1.svg?logo=mysql&logoColor=white)](#-database-schema)
[![Interface](https://img.shields.io/badge/UI-Bootstrap%204.6.2-7952B3.svg?logo=bootstrap&logoColor=white)](#-verified-compatibility)
[![Graphics](https://img.shields.io/badge/Graphics-Vector%20SVG-FFB13B.svg?logo=w3c&logoColor=white)](#-key-features)
[![License](https://img.shields.io/badge/License-GPLv3-green.svg?logo=gnu&logoColor=white)](LICENSE)

Interactive clinical encounter form for anatomical pain mapping in **OpenEMR**. Allows clinicians to record pain points on standardized vector body diagrams (Male / Female, Anterior and Posterior views), calculate exact relative coordinates, assign 0–10 NRS pain intensity, choose qualities from OpenEMR's `list_options`, and output clean tabular summaries into encounter notes.

---

## 📑 Table of Contents

- [✨ Key Features](#-key-features)
- [📸 Screenshots](#-screenshots)
- [🗄️ Database Schema](#-database-schema)
- [📊 Clinical Pain Scale](#-clinical-pain-scale)
- [📁 Directory Structure](#-directory-structure)
- [🚀 Installation & Setup](#-installation--setup)
- [🩺 Clinical Workflow](#-clinical-workflow)
- [🖨️ Encounter Reports](#-encounter-reports)
- [🛡️ Access Control (ACL)](#-access-control-acl)
- [💻 Verified Compatibility](#-verified-compatibility)
- [📜 License](#-license)

---

## ✨ Key Features

- 📍 **Vector Anatomical Mapping (SVG):**
  - **Body Diagrams:** High-resolution SVG diagrams for Adult Male and Adult Female across Anterior and Posterior views.
  - **Exact Coordinates:** Mathematical projection via screen CTM inversion (`getScreenCTM().inverse()`), recording sub-metric relative percentages (`x_pct`, `y_pct` from `0.000%` to `100.000%`) invariant to monitor DPI or browser zoom.
  - **Anatomical Whitelist:** Strict server-side verification of recognized region identifiers (`head`, `neck`, `chest`, `abdomen`, `pelvis`, `spine`, limbs, `genitals`, `perineum`).

- 🔍 **Interactive Zoom & Pan:**
  - **Mouse Wheel:** Smooth zoom centered on the cursor from 1x up to 5x.
  - **Right-Click Drag:** Free panning across the enlarged figure with native context menu suppression.
  - **Left-Click:** Dedicated to placing or inspecting pain marks (with 6px movement tolerance to prevent accidental marks during pan).
  - **On-Screen Controls:** Floating zoom buttons (+, 100%, −) convenient for laptops with touchpads.
  - **Touch Gestures:** Pinch-to-zoom and single-finger drag on mobile devices and clinical tablets.

- 🎨 **Dynamic Highlighting & Tinting:**
  - **Unmarked Zone Hover:** Moving the cursor over asymptomatic body regions lights up the area in soft light gray (`#d5dbdb`, 55% opacity) to clearly show exact zone boundaries before clicking.
  - **Severity Color Tints:** Marked body regions are automatically tinted with the color corresponding to the highest pain intensity recorded in that zone (`fill-opacity: 0.45`).
  - **High-Contrast Numbered Pins:** Circular pins displaying the intensity level (0–10) with elevation shadows and brightness on hover.
  - **Rich Multi-Line Tooltips:** Hovering over a marked zone displays a popover with the zone name and details for all pains registered there.

- 📊 **Horizontal Pain Scale Bar:**
  - A color-coded reference bar (levels 0 to 10) positioned directly beneath the anatomical map.
  - Displays the clinical progression from *Little pain* to *Extreme pain*.
  - Interactive: clicking any colored level in the bar sets the intensity slider when the mark dialog is open.

- 📋 **Structured Semiological Evaluation:**
  - **Intensity:** Numeric Rating Scale (NRS) 0 to 10.
  - **Pain Quality:** Dropdown populated from OpenEMR's `list_options` (`pain_zones_quality`), with a custom text field if "Other" is selected.
  - **Clinical Fields:** Radiation path, onset/chronology, duration, aggravating factors, relieving factors, and mark-specific notes.
  - **General Notes:** Encounter-level observations textarea.

- 📑 **Global Sidebar List:**
  - Centralized list displaying all marks recorded across all 4 body panels.
  - Instant navigation: clicking any card in the list automatically switches the panel to that figure and view.

---

## 📸 Screenshots

> *(Place screenshots in `docs/` or `assets/` to display them here)*

| Anatomical Map & Zoom Controls | Mark Assessment Dialog |
|:---:|:---:|
| ![Anatomical Map with Zones and Zoom](docs/screenshot_map.png) | ![Clinical Assessment Dialog](docs/screenshot_modal.png) |
| *Anatomical map with tinted zones, zoom controls, and bottom scale bar.* | *NRS intensity slider, pain quality dropdown, and semiology fields.* |

| Encounter Report |
|:---:|
| ![Tabular Encounter Report](docs/screenshot_report.png) |
| *Structured tabular summary integrated into the patient's encounter history.* |

---

## 🗄️ Database Schema

The module provisions three relational tables and populates two lists in `list_options` (`table.sql`):

1. **`form_pain_zones` (Form Header):**
   - Binds the clinical encounter (`id`, `date`, `pid`, `user`, `groupname`, `authorized`, `activity`).
   - Stores `general_notes` (up to 5,000 characters) and generated clinical summary (`summary_text`).

2. **`form_pain_zones_marks` (Pain Marks):**
   - Records each pain point: `figure`, `body_view`, `zone_id`, `laterality`, `x_pct`, `y_pct`, `intensity` (0–10), `pain_quality`, `radiation`, `onset`, `duration`, `aggravating`, `relieving`, `notes`, and active flag (`activity = 1`, soft-deleted if `0`).

3. **`form_pain_zones_history` (Audit Log):**
   - Records an entry on insert, update, or soft-delete with timestamp, user ID, and a JSON `snapshot` of the mark's full state.

4. **`list_options` (`pain_zones_level`):**
   - Preconfigured NRS levels (`level_0` to `level_10`) with hex color definitions (`stroke` and `fill`) and severity bands (`none`, `mild`, `moderate`, `severe`).

5. **`list_options` (`pain_zones_quality`):**
   - Clinical pain qualities (*sharp*, *dull*, *burning*, *throbbing*, *stabbing*, *cramping*, *pressing*, *shooting*, *aching*, *electric*, *tingling*, *heavy*, *other*).
   - Fully customizable and translatable in OpenEMR under **Administration > Lists**.

---

## 📊 Clinical Pain Scale

| NRS Level | Severity Band | Color | Hex (Stroke / Fill) | Clinical Significance |
|:---:|:---:|:---:|:---:|:---|
| **0** | **None** | ⚪ Grey | `#848484` / `#BDBDBD` | No pain / baseline resolved state |
| **1** | **Mild** | 🔵 Blue | `#1565C0` / `#42A5F5` | Very mild, barely noticeable |
| **2** | **Mild** | 🔵 Sky Blue | `#0277BD` / `#29B6F6` | Minor pain, does not interfere with daily life |
| **3** | **Mild** | 🟢 Teal | `#00695C` / `#26A69A` | Tolerable, manageable |
| **4** | **Moderate** | 🟢 Green | `#2E7D32` / `#66BB6A` | Begins to interfere with concentration |
| **5** | **Moderate** | 🟡 Lime | `#558B2F` / `#D4E157` | Distracting, limits routine activities |
| **6** | **Moderate** | 🟡 Amber | `#F9A825` / `#FFEE58` | Substantial impact on daily activities |
| **7** | **Severe** | 🟠 Orange | `#E65100` / `#FFA726` | Dominates senses, impedes movement |
| **8** | **Severe** | 🔴 Deep Orange | `#BF360C` / `#FF7043` | Severe functional limitation |
| **9** | **Severe** | 🔴 Crimson | `#B71C1C` / `#EF5350` | Intense pain, conversation impaired |
| **10** | **Severe** | 🟣 Purple | `#4A148C` / `#AB47BC` | Worst imaginable pain, immediate care required |

> [!TIP]
> Palette colors and level labels can be adjusted in OpenEMR under **Administration > Lists > Pain Zones Level**.

---

## 📁 Directory Structure

```text
interface/forms/pain_zones/
├── 📂 assets/
│   ├── female_anterior.svg     # Adult female anterior diagram
│   ├── female_posterior.svg    # Adult female posterior diagram
│   ├── male_anterior.svg       # Adult male anterior diagram
│   └── male_posterior.svg      # Adult male posterior diagram
├── info.txt                    # OpenEMR form display name
├── lang_custom.sql             # Latin American Spanish translations
├── LICENSE                     # GNU General Public License v3.0 text
├── new.php                     # Interactive map, editing, and view interface
├── pain_zones.inc.php          # Backend helpers, ACL checks, and palette queries
├── README.md                   # English technical documentation
├── README_es.md                # Spanish technical documentation
├── report.php                  # Tabular encounter report generator
├── save.php                    # Transactional database persistence endpoint
├── table.sql                   # Database table definitions and list_options seed
└── view.php                    # Redirect handler to new.php in view mode
```

---

## 🚀 Installation & Setup

### 1. File Placement
Copy or clone the `pain_zones` directory into your OpenEMR forms folder:
```bash
cp -r pain_zones /var/www/html/openemr/interface/forms/
chown -R www-data:www-data /var/www/html/openemr/interface/forms/pain_zones
```

### 2. Database Initialization
Run the SQL schema file to create tables and seed lists:
```bash
mysql -u openemr -p openemr < /var/www/html/openemr/interface/forms/pain_zones/table.sql
```

*(Optional)* If you want full Latin American Spanish translations:
```bash
mysql -u openemr -p openemr < /var/www/html/openemr/interface/forms/pain_zones/lang_custom.sql
```

### 3. OpenEMR Registration
1. Log in to OpenEMR with an administrator account.
2. Navigate to **Administration > Forms**.
3. In the unregistered forms tab, locate **Pain Zones** (`pain_zones`).
4. Click **Register** and then **Install DB** if prompted.

---

## 🩺 Clinical Workflow

1. **Open Form:** During an active encounter, select **Clinical Forms > Pain Zones**.
2. **Zoom & Position:** Use the mouse wheel or floating buttons to zoom in. Drag with the right mouse button to pan across the figure.
3. **Place Mark:** Left-click on any body zone. Enter the intensity (0–10), quality, radiation, and notes in the dialog.
4. **Edit or Remove:** Click any pin on the map or select an entry in the sidebar list to modify or delete a mark.
5. **Save:** Click **Save Form**. Data is persisted in a database transaction and the tab closes, refreshing the encounter view.

---

## 🖨️ Encounter Reports

The file `report.php` implements `pain_zones_report()`. It generates a clean HTML table detailing all marks, intensities, qualities, and notes, which OpenEMR embeds automatically into the patient's encounter record and printable chart export.

---

## 🛡️ Access Control (ACL)

Access is controlled via OpenEMR's standard `patients/med` section permissions:

- **Write Permission (`write` / `addonly`):** Allows users to add, edit, soft-delete marks, and save changes.
- **Read-Only Permission (`view` / `read`):** Allows users to view the form, zoom, pan, and read mark details in read-only mode with inputs disabled.
- **Unauthorized:** Users without read access to `patients/med` are blocked with an HTTP 403 response via `AccessDeniedHelper`.

---

## 💻 Verified Compatibility

> [!IMPORTANT]
> **Production-Tested Versions:**
> This module has been verified on:
> - **OpenEMR 8.2.0** (PHP 8.2 / 8.3)
> - **OpenEMR 8.4.1** (PHP 8.2 / 8.3)
>
> **Note on earlier versions:** Compatibility with OpenEMR 7.0 or earlier is not guaranteed because the code relies on modern OpenEMR 8.x architecture (`QueryUtils::inTransaction()`, `SessionWrapperFactory`, `PatientSessionUtil`, `EncounterSessionUtil`, PSR-4 namespaces, and `EncounterFormAccess`).

- **Databases:** MySQL 5.7+ / 8.0+ or MariaDB 10.4+.
- **Browsers:** Modern versions of Google Chrome, Mozilla Firefox, Microsoft Edge, and Safari with SVG and ES6 support.

---

## 📜 License

This project is licensed under the **GNU General Public License v3 (GPL-3.0)**. See the [`LICENSE`](LICENSE) file for the full license text. OpenEMR is a registered trademark of the OpenEMR Foundation.
