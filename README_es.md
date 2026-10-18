# 🩺 Formulario Clínico de Zonas de Dolor para OpenEMR (`pain_zones`)

[![Compatible con OpenEMR](https://img.shields.io/badge/OpenEMR-v7.0%20--%20v8.4%2B-blue.svg?logo=circle&logoColor=white)](https://www.open-emr.org)
[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![Base de Datos](https://img.shields.io/badge/Base%20de%20Datos-MySQL%20%7C%20MariaDB-4479A1.svg?logo=mysql&logoColor=white)](#-estructura-de-base-de-datos)
[![Interfaz](https://img.shields.io/badge/Interfaz-Bootstrap%204.6.2-7952B3.svg?logo=bootstrap&logoColor=white)](#-compatibilidad-y-requisitos-de-entorno)
[![Gráficos](https://img.shields.io/badge/Gr%C3%A1ficos-Vector%20SVG%201.1-FFB13B.svg?logo=w3c&logoColor=white)](#-características-clínicas-principales)
[![Estándar Clínico](https://img.shields.io/badge/Escala%20Cl%C3%ADnica-ENA%200--10-orange.svg?logo=heart&logoColor=white)](#-escala-clínica-de-dolor-y-bandas-de-severidad)
[![Seguridad](https://img.shields.io/badge/Seguridad-CSRF%20%2B%20ACL%20Guarded-success.svg?logo=shield&logoColor=white)](#-arquitectura-y-seguridad)
[![Licencia](https://img.shields.io/badge/Licencia-GPLv3-green.svg?logo=gnu&logoColor=white)](https://www.gnu.org/licenses/gpl-3.0.html)

Módulo y formulario de encuentro clínico interactivo para el mapeo anatómico vectorial de dolor corporal en **OpenEMR**, diseñado para flujos de trabajo hospitalarios, policlínicos ambulatorios, unidades de fisiatría, traumatología, reumatología, oncología y centros multidisciplinarios de manejo del dolor.

El módulo **Pain Zones (Zonas de Dolor)** permite a los profesionales de la salud registrar, visualizar y monitorizar con alta precisión el dolor informado por el paciente sobre esquemas anatómicos masculino y femenino (vistas anterior y posterior). Cada marca almacena coordenadas relativas exactas sobre el vector, subzona anatómica validada, nivel de dolor en escala numérica del 0 al 10 (Escala Numérica Analógica - ENA), cualidad del dolor, trayectoria de irradiación, tiempo de evolución, factores agravantes/atenuantes y notas clínicas individuales.

---

## 📑 Índice

- [✨ Características Clínicas Principales](#-características-clínicas-principales)
- [🔒 Arquitectura y Seguridad](#-arquitectura-y-seguridad)
- [🗄️ Estructura de Base de Datos](#-estructura-de-base-de-datos)
- [📊 Escala Clínica de Dolor y Bandas de Severidad](#-escala-clínica-de-dolor-y-bandas-de-severidad)
- [📁 Estructura del Proyecto](#-estructura-del-proyecto)
- [🚀 Instalación y Activación](#-instalación-y-activación)
- [🩺 Flujo de Trabajo Clínico](#-flujo-de-trabajo-clínico)
- [🖨️ Informes Clínicos e Integración con PDF](#-informes-clínicos-e-integración-con-pdf)
- [🛡️ Control de Acceso y Permisos (ACL)](#-control-de-acceso-y-permisos-acl)
- [💻 Compatibilidad y Requisitos de Entorno](#-compatibilidad-y-requisitos-de-entorno)
- [📜 Licencia](#-licencia)

---

## ✨ Características Clínicas Principales

- 📍 **Mapeo Anatómico Vectorial Interactivo (SVG):**
  - 🧍‍♂️ **Esquemas Anatómicos Bilaterales y por Sexo:** Diagramas corporales vectoriales estandarizados: Hombre y Mujer en vistas Anterior y Posterior.
  - 📐 **Proyección y Transformación Matricial Inversa:** Transformación matemática de coordenadas con matriz inversa (`getScreenCTM().inverse()`), calculando porcentajes submétricos exactos (`x_pct`, `y_pct` del `0.000%` al `100.000%`), independientes de la resolución de pantalla o zoom del navegador.
  - 🛡️ **Lista Blanca de Regiones Anatómicas:** Validación estricta de prefijos anatómicos permitidos (`head`, `neck`, `chest`, `abdomen`, `pelvis`, `spine`, `shoulder`, extremidades, `genitals`, `perineum`).
- 🎨 **Teñido Dinámico de Zonas por Severidad:**
  - 🌈 **Gradiente de Color por Severidad Pico:** Las regiones anatómicas se colorean automáticamente según el nivel de dolor más alto registrado en dicha zona (`fill-opacity: ~0.45` y trazo perimetral con el color correspondiente).
  - ⚪ **Estado Basal Asintomático:** Las marcas con intensidad `0` (dolor resuelto / asintomático) se representan con pines grises neutros sin teñir la zona corporal subyacente.
  - 🏷️ **Pines de Alto Contraste:** Pines circulares con el número de intensidad renderizados por encima de la capa anatómica con sutiles sombras proyectadas.
- 📋 **Protocolo de Evaluación Semiológica Integral:**
  - 🔢 **Intensidad:** Escala Numérica Analógica (ENA / NRS) de 0 a 10.
  - ↔️ **Lateralidad:** Derivada automáticamente por el servidor (`left`, `right`, o `midline`) según la anatomía.
  - ⚡ **Cualidad del Dolor:** Punzante, sordo, opresivo, urente/ardoroso, pulsátil, cólico, fulgurante, etc.
  - 🎯 **Irradiación:** Proyección anatómica o trayecto radicular/dermatomérico.
  - ⏱️ **Patrón Temporal:** Inicio preciso (ej. *hace 3 días post-esfuerzo*) y duración (ej. *intermitente, episodios de 2 horas*).
  - ⚖️ **Factores Moduladores:** Agravantes mecánicos o posturales y maniobras/fármacos de alivio.
  - 📝 **Notas Clínicas:** Observaciones puntuales por marca y notas generales del encuentro.
- 🌐 **Gestión Global en Panel Lateral:**
  - 📑 **Catálogo Unificado de Marcas:** Registro centralizado en barra lateral con todas las marcas del paciente a lo largo de los 4 paneles, identificadas con etiquetas claras (ej. `Femenino / Posterior`).
  - 🔄 **Navegación y Sincronización Automática:** Al hacer clic en una marca de otro panel, el sistema sincroniza automáticamente los selectores de figura/vista, inyecta el SVG correspondiente, actualiza los contadores en tiempo real y abre la ficha de la marca.
- 📄 **Resumen e Informe Clínico para Ficha y PDF:**
  - 🖨️ **Motor de Renderizado sin Dependencias Externas:** Generador de informe en texto tabular (`report.php`) integrado de manera nativa en el historial del encuentro y en el reporte imprimible/PDF del paciente.
  - 📋 **Diseño Tabular Médico Limpio:** Formato estructurado optimizado para exportación y archivo clínico en papel.

---

## 🔒 Arquitectura y Seguridad

Diseñado siguiendo las directrices de seguridad de grado médico del núcleo de OpenEMR:

- 🧱 **Tipado Estricto de PHP:** `declare(strict_types=1);` aplicado en todos los archivos de procesamiento.
- 🛡️ **Protección de Sesión y Aislamiento de Paciente:**
  - 🆔 **Verificación de Paciente y Encuentro:** Validación del paciente en sesión con `PatientSessionUtil::getPid()` y verificación de encuentro activo con `EncounterSessionUtil::getEncounter()`.
  - 🚫 **Prevención de Acceso Cruzado:** Aislamiento estricto de registros con `EncounterFormAccess::assertFormBelongsToSessionPatient($formId, 'pain_zones')`.
- 🔑 **Mitigación de Ataques CSRF:**
  - 🎫 **Tokens Criptográficos:** Generación de token con `CsrfUtils::collectCsrfToken(session: $session)` en el formulario.
  - 🛑 **Validación Estricta en POST:** Comprobación en el backend con `CsrfUtils::checkCsrfInput(INPUT_POST)`, respondiendo con código HTTP 403 claro ante cualquier inconsistencia de seguridad.
- 👥 **Control de Acceso Granular (ACL):**
  - 📖 **Autorización de Lectura:** Requerida con `pz_requireRead()` sobre `patients/med`.
  - ✍️ **Autorización de Escritura:** Controlada con `pz_canWrite('new')` y `pz_canWrite('update')`.
  - 👁️ **Modo Solo Lectura Nativo:** Personal auditor o usuarios con permisos de solo lectura pueden explorar el mapa anatómico, hacer clic en los pines y revisar la semiología de cada zona con los campos bloqueados, sin posibilidad de alterar los datos.
- 🧼 **Saneamiento e Integridad Transaccional:**
  - 🧹 **Depuración de Entradas:** Validación de caracteres de control ilegítimos (`\x00-\x08`, `\x0B-\x0C`, `\x0E-\x1F`, `\x7F`) y longitudes máximas con `pz_sanitizeText`.
  - ⚛️ **Transacciones Atómicas:** Operaciones de persistencia encapsuladas en transacciones atómicas (`QueryUtils::inTransaction()`) que garantizan consistencia absoluta entre la cabecera del encuentro, las marcas nuevas, las modificaciones y las bajas lógicas.
  - 📜 **Pista de Auditoría Inmutable:** Registro histórico en `form_pain_zones_history` con instantáneas JSON completas de cada cambio para cumplimiento legal y normativo.

---

## 🗄️ Estructura de Base de Datos

El módulo crea tres tablas relacionales y registra opciones de configuración clínica en `list_options`:

1. 📑 **`form_pain_zones` (Cabecera del Formulario):**
   - 🔑 Vincula el encuentro (`id`, `date`, `pid`, `user`, `groupname`, `authorized`, `activity`).
   - 📝 Almacena notas generales del formulario (`general_notes`, hasta 5.000 caracteres) y resumen clínico (`summary_text`).
2. 📍 **`form_pain_zones_marks` (Marcas de Dolor y Datos Clínicos):**
   - 🔗 Registra cada punto de dolor vinculado a `form_id` y `pid`.
   - 🗂️ Campos: `figure`, `body_view`, `zone_id`, `laterality`, `x_pct`, `y_pct`, `intensity` (0 a 10), `pain_quality`, `radiation`, `onset`, `duration`, `aggravating`, `relieving`, `notes` y estado de actividad (baja lógica).
3. 📜 **`form_pain_zones_history` (Pista de Auditoría):**
   - ⏱️ Bitácora inmutable de eventos `insert`, `update` y `delete` con marca de tiempo, usuario responsable y snapshot JSON completo del registro.
4. 🎨 **`list_options` (`pain_zones_level`):**
   - 🏷️ 11 niveles preconfigurados (`level_0` a `level_10`) con paleta de colores hexadecimales (`stroke` y `fill`) y bandas de severidad clínica (`none`, `mild`, `moderate`, `severe`).

---

## 📊 Escala Clínica de Dolor y Bandas de Severidad

| 🔢 Nivel ENA | 🏷️ Banda Clínica | 🎨 Paleta Visual | 🖌️ Hex (Trazo / Relleno) | 📋 Interpretación Clínica |
|:---:|:---:|:---:|:---:|:---|
| **0** | **Ninguno** | ⚪ Gris | `#848484` / `#BDBDBD` | 🟢 Ausencia de dolor / dolor basal resuelto |
| **1** | **Leve** | 🔵 Azul | `#1565C0` / `#42A5F5` | 🔹 Dolor muy leve, apenas perceptible |
| **2** | **Leve** | 🔵 Celeste | `#0277BD` / `#29B6F6` | 🔹 Dolor menor, no interfiere con las actividades de la vida diaria |
| **3** | **Leve** | 🟢 Verde Azulado | `#00695C` / `#26A69A` | 🔹 Dolor tolerable, manejable sin fármacos de rescate |
| **4** | **Moderado** | 🟢 Verde | `#2E7D32` / `#66BB6A` | 🔸 Dolor moderado, comienza a interferir con la concentración |
| **5** | **Moderado** | 🟡 Verde Lima | `#558B2F` / `#D4E157` | 🔸 Dolor molesto, limita ciertas actividades cotidianas |
| **6** | **Moderado** | 🟡 Ámbar | `#F9A825` / `#FFEE58` | 🔸 Dolor importante, impacto evidente en las tareas habituales |
| **7** | **Severo** | 🟠 Naranja | `#E65100` / `#FFA726` | 🔺 Dolor severo, domina la atención, dificulta la movilización |
| **8** | **Severo** | 🔴 Naranja Rojizo | `#BF360C` / `#FF7043` | 🔺 Dolor intenso, limitación funcional significativa |
| **9** | **Severo** | 🔴 Carmesí | `#B71C1C` / `#EF5350` | 🚨 Dolor intolerable, paciente con llanto o dificultad para hablar |
| **10** | **Severo** | 🟣 Púrpura | `#4A148C` / `#AB47BC` | ⚡ Peor dolor imaginable, emergencia médica / dolor agudo refractario |

> [!TIP]
> 🎨 **Nota de Personalización:** Los colores y títulos se pueden personalizar en cualquier momento desde la interfaz de OpenEMR en **Administración > Listas > Pain Zones Level**.

---

## 📁 Estructura del Proyecto

```text
interface/forms/pain_zones/
├── 📂 assets/
│   ├── 🧍‍♀️ female_anterior.svg   # 72 regiones anatómicas validadas
│   ├── 🧍‍♀️ female_posterior.svg  # 58 regiones anatómicas validadas
│   ├── 🧍‍♂️ male_anterior.svg     # 74 regiones anatómicas validadas
│   └── 🧍‍♂️ male_posterior.svg    # 58 regiones anatómicas validadas
├── ⚙️ pain_zones.inc.php        # Librería principal, parser SVG, validadores y ACL
├── 🖥️ new.php                   # Interfaz clínica completa (alta, edición y lectura)
├── 💾 save.php                  # Endpoint transaccional con respuestas JSON estructuradas
├── 🔀 view.php                  # Enrutador seguro con redirección hacia new.php?id=N
├── 🖨️ report.php                # Generador de reportes tabulares para ficha e impresión PDF
├── 🗃️ table.sql                 # Estructura SQL y catálogo inicial de listas
├── 🌐 lang_custom.sql            # Traducciones al Español (Latinoamericano Hospital) y sincronización
└── 📄 info.txt                  # Descriptor de registro del formulario en OpenEMR
```

---

## 🚀 Instalación y Activación

### 1️⃣ 📁 Copiar los Archivos del Módulo
Coloque la carpeta `pain_zones` dentro del directorio de formularios de su instalación de OpenEMR:
```bash
cp -r pain_zones /var/www/localhost/htdocs/openemr/interface/forms/
```

### 2️⃣ 🗄️ Creación de Tablas en la Base de Datos
Importe el archivo `table.sql` en su base de datos de OpenEMR:
```bash
mysql -u openemr -p openemr < /var/www/localhost/htdocs/openemr/interface/forms/pain_zones/table.sql
```

### 3️⃣ 🌐 Carga de Traducciones al Español (Recomendado)
Para cargar y sincronizar de forma automática las traducciones clínicas en Español (Latinoamericano Hospital):
```bash
mysql -u openemr -p openemr < /var/www/localhost/htdocs/openemr/interface/forms/pain_zones/lang_custom.sql
```

### 4️⃣ ⚙️ Registro en la Administración de OpenEMR
1. 🔐 Inicie sesión en OpenEMR con una cuenta con perfil de **Administrador**.
2. 🧭 Vaya a **Administración > Formularios > Administración de Formularios**.
3. 🔎 En la sección **Formularios No Registrados**, busque **Pain Zones (`pain_zones`)**.
4. 📝 Haga clic en **Registrar**.
5. 🗄️ Una vez registrado, haga clic en **Instalar Base de Datos**.
6. 🏷️ Configure el apodo del formulario (por ejemplo: `Zonas de Dolor`) y actívelo (🟢).
7. 🎉 El formulario estará disponible inmediatamente dentro de cualquier consulta en el menú **Formularios Clínicos**.

---

## 🩺 Flujo de Trabajo Clínico

1. 📂 **Apertura del Formulario:**
   - 🩺 Durante un encuentro médico activo, seleccione **Formularios Clínicos > Zonas de Dolor**.
   - 🔄 El formulario se abre mostrando por defecto el panel que tenga más marcas registradas (o *Hombre Adulto Anterior* en fichas nuevas).
2. 📍 **Registro de una Marca de Dolor:**
   - 👆 Haga clic sobre cualquier región del esquema anatómico.
   - 💬 Se abrirá la ventana de evaluación clínica:
     - 🎚️ Indique la intensidad (0 a 10) usando la barra deslizante. El fondo cambia de color en tiempo real.
     - 📝 Documente la cualidad del dolor, hacia dónde se irradia, momento de inicio y factores agravantes o aliviadores.
     - ✅ Presione **Confirmar** para guardar la marca en memoria de trabajo.
   - 🎨 La zona se teñirá automáticamente según la severidad y aparecerá un pin numerado en las coordenadas seleccionadas.
3. ✏️ **Modificación o Eliminación:**
   - 🎯 Haga clic sobre cualquier pin en el esquema corporal o presione **Editar** (✏️) desde el listado lateral.
   - 🔄 Modifique los parámetros deseados o presione **Eliminar Marca** (🗑️) si el dolor ha cesado o fue marcado por error.
4. 💾 **Guardado en la Ficha del Paciente:**
   - 📋 Si lo desea, agregue consideraciones globales en el recuadro de **Notas Generales**.
   - 💾 Haga clic en **Guardar**. Los datos se enviarán de forma asíncrona mediante `fetch`, se grabarán en una transacción atómica y la pestaña se cerrará automáticamente actualizando el encuentro.

---

## 🖨️ Informes Clínicos e Integración con PDF

El módulo incluye soporte nativo mediante `pain_zones_report()` en `report.php`:
- 📄 Totalmente integrado en el **Informe del Paciente**, **Resumen de la Consulta** y la **Generación de PDF**.
- 📊 Presenta una tabla con tipografía limpia y clara:
  - 🗺️ Zona anatómica con formato legible.
  - ↔️ Lateralidad, figura y vista corporal.
  - 🔢 Nivel de intensidad ENA y banda de severidad.
  - 📝 Detalles clínicos (cualidad, irradiación, cronología, factores y notas específicas) agrupados en un renglón complementario para evitar ensanchar la tabla en documentos físicos.
  - 📋 Notas generales del encuentro al pie de la tabla.

---

## 🛡️ Control de Acceso y Permisos (ACL)

| 🩺 Rol Clínico / Administrativo | 👁️ Permiso de Lectura (`patients/med`) | ✏️ Permiso de Escritura (`patients/med`) | 🎯 Experiencia en Zonas de Dolor |
|:---|:---:|:---:|:---|
| 👨‍⚕️ **Médico Tratante / Especialista** | ✅ Sí | ✅ Sí (`write`) | 🟢 Alta, modificación, eliminación de marcas y guardado completo |
| 👩‍⚕️ **Enfermería / Residente** | ✅ Sí | ✅ Sí (`write`/`addonly`) | 🟢 Registro completo de nuevas marcas y consultas |
| 🔍 **Auditoría / Facturación / Consulta** | ✅ Sí | ❌ No | 🟡 **Modo Solo Lectura**: Puede explorar diagramas, cambiar de vistas y abrir el modal para leer la semiología. Todos los controles de guardado y edición permanecen deshabilitados |
| 🚫 **Rol no autorizado** | ❌ No | ❌ No | 🔴 Acceso denegado con código HTTP 403 mediante `AccessDeniedHelper` |

---

## 💻 Compatibilidad y Requisitos de Entorno

- 🐘 **Versión de PHP:** PHP 8.2, 8.3 y 8.4 (con `strict_types=1`).
- 🗄️ **Motores de Base de Datos:** MySQL 5.7+ / 8.0+ o MariaDB 10.4+.
- 🎨 **Pila Web:** JavaScript ES6+ nativo, DOM SVG 1.1, Bootstrap 4.6.2 (estándar de OpenEMR 8.x).
- 🌐 **Navegadores Soportados:**
  - 🌐 Google Chrome (v90+)
  - 💻 Microsoft Edge (v90+)
  - 🦊 Mozilla Firefox (v88+)
  - 🧭 Apple Safari (v14+)
- 📱 **Dispositivos:**
  - 🖥️ Estaciones de trabajo médicas y monitores de diagnóstico.
  - 💻 Computadores portátiles y carritos clínicos rodantes (COWs/WOWs).
  - 📱 Tabletas y pantallas táctiles de uso clínico (iPadOS, Windows Surface, Android).

---

## 📜 Licencia

Distribuido bajo los términos de la **Licencia Pública General de GNU v3 (GPL-3.0)**. OpenEMR es una marca registrada de la OpenEMR Foundation.
