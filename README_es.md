# 🩺 Formulario de Zonas de Dolor para OpenEMR (`pain_zones`)

[![Probado en OpenEMR](https://img.shields.io/badge/OpenEMR-8.2.0%20%7C%208.4.1%20(Probado)-blue.svg?logo=circle&logoColor=white)](https://www.open-emr.org)
[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![Base de Datos](https://img.shields.io/badge/Base%20de%20Datos-MySQL%20%7C%20MariaDB-4479A1.svg?logo=mysql&logoColor=white)](#-estructura-de-base-de-datos)
[![Interfaz](https://img.shields.io/badge/Interfaz-Bootstrap%204.6.2-7952B3.svg?logo=bootstrap&logoColor=white)](#-compatibilidad-y-requisitos-de-entorno)
[![Gráficos](https://img.shields.io/badge/Gr%C3%A1ficos-Vector%20SVG-FFB13B.svg?logo=w3c&logoColor=white)](#-características-principales)
[![Licencia](https://img.shields.io/badge/Licencia-GPLv3-green.svg?logo=gnu&logoColor=white)](LICENSE)

Formulario de encuentro clínico para el mapeo anatómico interactivo de dolor en **OpenEMR**. Permite registrar marcas sobre diagramas corporales vectoriales (Hombre / Mujer, vistas Anterior y Posterior), calcular coordenadas submétricas exactas, clasificar la intensidad (Escala Numérica Analógica ENA 0–10), seleccionar cualidades clínicas del dolor configurables desde `list_options` y generar reportes tabulares para la ficha médica.

---

## 📑 Índice

- [✨ Características Principales](#-características-principales)
- [📸 Capturas de Pantalla](#-capturas-de-pantalla)
- [🗄️ Estructura de Base de Datos](#-estructura-de-base-de-datos)
- [📊 Escala Clínica de Dolor](#-escala-clínica-de-dolor)
- [📁 Estructura del Directorio](#-estructura-del-directorio)
- [🚀 Instalación y Activación](#-instalación-y-activación)
- [🩺 Flujo de Uso](#-flujo-de-uso)
- [🖨️ Reportes del Encuentro](#-reportes-del-encuentro)
- [🛡️ Control de Acceso (ACL)](#-control-de-acceso-acl)
- [💻 Compatibilidad Verificada](#-compatibilidad-verificada)
- [📜 Licencia](#-licencia)

---

## ✨ Características Principales

- 📍 **Mapeo Anatómico Vectorial (SVG):**
  - **Figuras Anatómicas:** Diagramas corporales vectoriales para Adulto Masculino y Adulto Femenino, cada uno con vistas Anterior y Posterior.
  - **Precisión de Coordenadas:** Transformación matemática mediante la matriz inversa de pantalla (`getScreenCTM().inverse()`), asegurando coordenadas relativas exactas (`x_pct`, `y_pct` del `0.000%` al `100.000%`) independientes de la resolución del monitor o nivel de zoom.
  - **Validación de Regiones:** Control estricto de identificadores anatómicos reconocidos (`head`, `neck`, `chest`, `abdomen`, `pelvis`, `spine`, extremidades, `genitals`, `perineum`).

- 🔍 **Zoom y Desplazamiento (Pan) Integrados:**
  - **Rueda del ratón:** Acercamiento focalizado en el cursor hasta 5x y alejamiento hasta 1x.
  - **Botón derecho presionado:** Arrastre y paneo libre sobre la figura ampliada sin activar el menú contextual del navegador.
  - **Botón izquierdo:** Exclusivo para registrar puntos de dolor o consultar marcas existentes (con tolerancia de 6px contra arrastres accidentales).
  - **Controles flotantes:** Botones en pantalla (+, 100%, −) accesibles para pantallas táctiles o portátiles con touchpad.
  - **Soporte táctil:** Gestos de pellizcar para zoom y arrastre con un dedo en dispositivos móviles y tabletas.

- 🎨 **Resaltado y Teñido Dinámico:**
  - **Resaltado de zonas sin marca:** Al pasar el cursor sobre regiones anatómicas que no tienen dolor registrado, la zona se colorea en un gris suave delimitando claramente sus límites antes de hacer clic.
  - **Teñido por severidad:** Las zonas con dolor se tiñen automáticamente con el color del nivel más alto registrado en dicha zona (`fill-opacity: 0.45`).
  - **Pines estáticos y legibles:** Marcadores circulares con el número de intensidad (0 a 10), con sombreado y resalte de contraste al pasar el ratón.
  - **Tooltips informativos:** Al pasar el ratón por una zona marcada, un tooltip contextual detalla la zona y lista todas las marcas asociadas con su severidad, cualidad, irradiación y notas.

- 📊 **Barra Visual Inferior de Escala de Dolor:**
  - Franja horizontal continua con los niveles del 0 al 10 en la parte inferior del mapa anatómico.
  - Muestra la paleta de colores oficial de menor a mayor intensidad (*Poco dolor* a *Dolor extremo*).
  - Interactiva: al tener abierto el modal de registro, hacer clic en cualquier celda de color ajusta automáticamente el deslizador de intensidad.

- 📋 **Evaluación Semiológica Estructurada:**
  - **Intensidad:** Escala Numérica Analógica (ENA / NRS) de 0 a 10.
  - **Cualidad del Dolor:** Selector desplegable alimentado por la tabla `list_options` (`pain_zones_quality`), permitiendo también especificar texto libre si se elige "Otro".
  - **Datos adicionales:** Irradiación anatómica, inicio/cronología, duración, factores agravantes, factores atenuantes y notas particulares de la marca.
  - **Notas generales:** Cuadro de texto para observaciones globales del encuentro médico.

- 📑 **Listado Lateral Global:**
  - Panel lateral que lista todas las marcas del encuentro a lo largo de las 4 vistas corporales.
  - Navegación directa: hacer clic en una tarjeta del listado cambia automáticamente a la figura y vista correspondiente.

---

## 📸 Capturas de Pantalla

| Vista Principal y Mapa Anatómico | Modal de Registro de Marca |
|:---:|:---:|
| docs/pain_zone_1.png | docs/pain_zone_3.png |
| *Mapa con zonas coloreadas, controles de zoom y escala horizontal.* | *Deslizador de intensidad, selector de cualidad y datos semiológicos.* |

| Reporte del Encuentro |
|:---:|
| docs/pain_zone_5.png |
| *Detalle de zonas y marcas integrado en el historial del paciente.* |

---

## 🗄️ Estructura de Base de Datos

El módulo utiliza tres tablas relacionales y dos listas en `list_options` (`table.sql`):

1. **`form_pain_zones` (Cabecera del Formulario):**
   - Vincula el encuentro médico (`id`, `date`, `pid`, `user`, `groupname`, `authorized`, `activity`).
   - Almacena notas generales del formulario (`general_notes`, hasta 5.000 caracteres) y resumen clínico (`summary_text`).

2. **`form_pain_zones_marks` (Marcas de Dolor):**
   - Almacena cada punto marcado en el cuerpo: `figure`, `body_view`, `zone_id`, `laterality`, `x_pct`, `y_pct`, `intensity` (0 a 10), `pain_quality`, `radiation`, `onset`, `duration`, `aggravating`, `relieving`, `notes` y estado de actividad (`activity = 1`, o `0` para bajas lógicas).

3. **`form_pain_zones_history` (Registro de Auditoría):**
   - Guarda una fila por cada inserción, modificación o eliminación lógica, registrando fecha/hora, usuario responsable y un volcado JSON (`snapshot`) con el estado de la marca.

4. **`list_options` (`pain_zones_level`):**
   - Catálogo de niveles de dolor (`level_0` a `level_10`) con definición de colores hexadecimales (`stroke` y `fill`) y bandas clínicas (`none`, `mild`, `moderate`, `severe`).

5. **`list_options` (`pain_zones_quality`):**
   - Catálogo de cualidades clínicas del dolor (*sharp*, *dull*, *burning*, *throbbing*, *stabbing*, *cramping*, *pressing*, *shooting*, *aching*, *electric*, *tingling*, *heavy*, *other*).
   - Administrable y traducible directamente desde la interfaz de OpenEMR en **Administración > Listas**.

---

## 📊 Escala Clínica de Dolor

| Nivel ENA | Banda Clínica | Color | Hex (Trazo / Relleno) | Interpretación |
|:---:|:---:|:---:|:---:|:---|
| **0** | **Ninguno** | ⚪ Gris | `#848484` / `#BDBDBD` | Ausencia de dolor / dolor resuelto |
| **1** | **Leve** | 🔵 Azul | `#1565C0` / `#42A5F5` | Apenas perceptible |
| **2** | **Leve** | 🔵 Celeste | `#0277BD` / `#29B6F6` | Dolor menor |
| **3** | **Leve** | 🟢 Verde Azulado | `#00695C` / `#26A69A` | Dolor tolerable |
| **4** | **Moderado** | 🟢 Verde | `#2E7D32` / `#66BB6A` | Comienza a interferir con la concentración |
| **5** | **Moderado** | 🟡 Verde Lima | `#558B2F` / `#D4E157` | Molesto, limita ciertas actividades |
| **6** | **Moderado** | 🟡 Ámbar | `#F9A825` / `#FFEE58` | Interfiere con las tareas cotidianas |
| **7** | **Severo** | 🟠 Naranja | `#E65100` / `#FFA726` | Dificulta la movilización |
| **8** | **Severo** | 🔴 Naranja Rojizo | `#BF360C` / `#FF7043` | Limitación funcional importante |
| **9** | **Severo** | 🔴 Carmesí | `#B71C1C` / `#EF5350` | Dolor intenso |
| **10** | **Severo** | 🟣 Púrpura | `#4A148C` / `#AB47BC` | Máxima intensidad imaginable |

> [!TIP]
> Los colores y nombres de los niveles se pueden ajustar desde **Administración > Listas > Pain Zones Level**.

---

## 📁 Estructura del Directorio

```text
interface/forms/pain_zones/
├── 📂 assets/
│   ├── female_anterior.svg     # Diagrama mujer vista anterior
│   ├── female_posterior.svg    # Diagrama mujer vista posterior
│   ├── male_anterior.svg       # Diagrama hombre vista anterior
│   └── male_posterior.svg      # Diagrama hombre vista posterior
├── info.txt                    # Nombre del formulario registrado por OpenEMR
├── lang_custom.sql             # Traducciones de interfaz en español latinoamericano
├── LICENSE                     # Texto de la Licencia GNU GPL v3.0
├── new.php                     # Interfaz interactiva de captura, edición y vista
├── pain_zones.inc.php          # Lógica auxiliar, validación, paletas y consultas
├── README.md                   # Documentación técnica en inglés
├── README_es.md                # Documentación técnica en español
├── report.php                  # Generador del informe tabular del encuentro
├── save.php                    # Endpoint transaccional para persistencia de datos
├── table.sql                   # Estructura SQL inicial y registros de list_options
└── view.php                    # Redirección de consulta hacia new.php
```

---

## 🚀 Instalación y Activación

### 1. Ubicación de Archivos
Copie o clone la carpeta `pain_zones` dentro del directorio de formularios de OpenEMR:
```bash
cp -r pain_zones /var/www/html/openemr/interface/forms/
chown -R www-data:www-data /var/www/html/openemr/interface/forms/pain_zones
```

### 2. Base de Datos
Ejecute el script SQL para crear las tablas y las listas:
```bash
mysql -u openemr -p openemr < /var/www/html/openemr/interface/forms/pain_zones/table.sql
```

*(Opcional)* Si desea registrar las traducciones completas al español:
```bash
mysql -u openemr -p openemr < /var/www/html/openemr/interface/forms/pain_zones/lang_custom.sql
```

### 3. Registro en OpenEMR
1. Inicie sesión en OpenEMR con una cuenta de Administrador.
2. Navegue a **Administración > Formularios**.
3. En la pestaña de formularios no registrados, busque **Pain Zones** (`pain_zones`).
4. Haga clic en **Registrar** y luego en **Instalar Base de Datos** si se solicita.

---

## 🩺 Flujo de Uso

1. **Apertura:** En un encuentro clínico activo, seleccione **Formularios Clínicos > Pain Zones**.
2. **Navegación y Zoom:** Utilice la rueda del ratón o los botones flotantes para acercar la región de interés. Arrastre con el botón derecho para desplazar la figura.
3. **Marcar Dolor:** Haga clic izquierdo sobre la zona corporal. Se abrirá el modal para registrar la intensidad (0 a 10), cualidad, irradiación y factores asociados.
4. **Editar o Eliminar:** Haga clic sobre un pin circular en el mapa o use el listado lateral para modificar o eliminar una marca.
5. **Guardar:** Haga clic en **Guardar Formulario**. Los cambios se graban en una sola transacción en la base de datos y la pestaña se cierra actualizando el encuentro.

---

## 🖨️ Reportes del Encuentro

El archivo `report.php` define la función `pain_zones_report()`. Genera una tabla HTML limpia con el detalle de las marcas (zona, lateralidad, intensidad, cualidad, notas), la cual OpenEMR integra de forma estándar en el historial del encuentro y en el resumen imprimible de la consulta médica.

---

## 🛡️ Control de Acceso (ACL)

El módulo utiliza el sistema de permisos estándar de OpenEMR sobre la sección `patients/med`:

- **Permiso de Escritura (`write` / `addonly`):** Permite registrar marcas, modificarlas, eliminarlas y guardar el formulario.
- **Permiso de Solo Lectura (`view` / `read`):** Permite abrir el formulario, hacer zoom, explorar el mapa y ver el detalle de cada dolor con los controles deshabilitados (modo solo lectura).
- **Sin acceso:** Si el usuario carece de permisos de lectura sobre `patients/med`, el sistema bloquea el acceso mediante `AccessDeniedHelper`.

---

## 💻 Compatibilidad Verificada

> [!IMPORTANT]
> **Versiones Verificadas en Producción:**
> Este módulo ha sido probado y validado específicamente en:
> - **OpenEMR 8.2.0** (PHP 8.2 / 8.3)
> - **OpenEMR 8.4.1** (PHP 8.2 / 8.3)
>
> **Nota sobre versiones anteriores:** No se garantiza el funcionamiento en OpenEMR 7.0 o versiones previas debido a que el código hace uso de clases y utilidades modernas del núcleo de OpenEMR (`QueryUtils::inTransaction()`, `SessionWrapperFactory`, `PatientSessionUtil`, `EncounterSessionUtil`, namespaces PSR-4 y `EncounterFormAccess`).

- **Bases de datos compatibles:** MySQL 5.7+ / 8.0+ o MariaDB 10.4+.
- **Navegadores:** Google Chrome, Mozilla Firefox, Microsoft Edge y Safari (versiones modernas con soporte SVG y ES6).

---

## 📜 Licencia

Este módulo es software libre distribuido bajo los términos de la **Licencia Pública General de GNU v3 (GPL-3.0)**. Consulte el archivo [`LICENSE`](LICENSE) para obtener los términos completos. OpenEMR es una marca registrada de la OpenEMR Foundation.
