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
- [🔎 Comparación de Visitas y Mapa de Calor](#-comparación-de-visitas-y-mapa-de-calor)
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

- 🧾 **Resumen Clínico Editable (`summary_text`):**
  - Debajo de Notas Generales hay un cuadro **Texto del Resumen**. Al confirmar o eliminar una marca se rellena automáticamente a partir de las marcas actuales y las notas generales, con una plantilla fija y determinista (sin IA, sin generación libre).
  - Un renglón por marca ordenado por intensidad descendente: zona anatómica traducida con su lateralidad, banda de severidad y `N/10`, y luego solo los campos semiológicos con valor (cualidad, irradiación, inicio, duración, agravantes, atenuantes, notas). Las notas generales se agregan como renglón final; si no hay marcas se muestra una frase fija traducible.
  - El clínico puede corregir el texto antes de guardar y ese texto revisado se persiste con el formulario. El enlace **Regenerar** lo reconstruye desde las marcas actuales. Si el campo no llega (cliente antiguo), el servidor lo regenera dentro de la transacción de guardado.
  - Se guarda como texto plano (nunca HTML) y sirve de contexto para la nota SOAP.

- 🔎 **Comparación de Visitas de Solo Lectura (`compare.php`):**
  - Vista de solo lectura, por paciente, que correlaciona las marcas **confirmadas** de visitas anteriores en un rango de fechas seleccionable (últimos 30 días, 3/6/12 meses, todo el historial o un rango personalizado).
  - **Modo Superposición:** dibuja las marcas de hasta cinco visitas sobre la misma figura, con un color distinto por visita y selección automática de las 3 visitas más recientes.
  - **Modo Mapa de Calor:** colorea cada zona anatómica según la métrica elegida (**Máxima** por defecto, **Promedio** o **Recuento** de visitas), calculada a partir de la intensidad más alta registrada por zona y visita.
  - Incluye tabla de totales por visita, tabla de calor por zona, leyenda de colores, diseño imprimible y accesos directos desde el formulario y el reporte del encuentro.

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
   - Almacena notas generales del formulario (`general_notes`, hasta 5.000 caracteres) y el resumen editable en texto plano `summary_text` (autocompletado desde las marcas y revisable antes de guardar, con regeneración del servidor como respaldo; ver `pz_buildSummary()` en `pain_zones.inc.php`).

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
├── compare.php                 # Vista de solo lectura: comparación de visitas y mapa de calor
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
2. **Figura:** el diagrama corporal está **fijado al sexo de nacimiento registrado del paciente** (`patient_data.sex`, el campo *Sex at birth*): una paciente **Female** ve el diagrama femenino, **Male** el masculino, y cualquier otro valor (vacío o `UNK`) cae al diagrama masculino **con un aviso visible** que pide completar el sexo en la ficha del paciente. La figura **no es seleccionable**. Si un formulario guardado todavía tiene marcas dibujadas sobre la figura opuesta (datos heredados), aparece un selector de figura que ofrece ambos paneles para que esas marcas sigan siendo accesibles — nunca se ocultan.
3. **Navegación y Zoom:** Utilice la rueda del ratón o los botones flotantes para acercar la región de interés. Arrastre con el botón derecho para desplazar la figura.
4. **Marcar Dolor:** Haga clic izquierdo sobre la zona corporal. Se abrirá el modal para registrar la intensidad (0 a 10), cualidad, irradiación y factores asociados.
5. **Editar o Eliminar:** Haga clic sobre un pin circular en el mapa o use el listado lateral para modificar o eliminar una marca.
6. **Revisar y Guardar:** Al confirmar cada marca se rellena el cuadro editable **Texto del Resumen**; corríjalo si hace falta (o pulse **Regenerar**). Al hacer clic en **Guardar Formulario** se persisten las marcas y el resumen revisado en una sola transacción, y la pestaña se cierra actualizando el encuentro.

---

## 🔎 Comparación de Visitas y Mapa de Calor

`compare.php` es una página **de solo lectura**, por paciente, que pone el cuadro de dolor actual junto a los anteriores. Nunca escribe en la base de datos y requiere el mismo permiso de lectura `patients/med` que el formulario. Solo lee formularios **confirmados** (`review_status = 'confirmed'`) y activos (`activity = 1`) del paciente en sesión; los borradores y las visitas eliminadas se ignoran.

**Filtros (validados en el servidor; los valores desconocidos vuelven a los predeterminados):**

- **Rango de Fechas:** últimos 30 días, últimos 3 / 6 / 12 meses (predeterminado), todo el historial o un rango personalizado `Desde`–`Hasta`. Las fechas imposibles vuelven al rango de 12 meses y un rango invertido se ordena automáticamente.
- **Figura / Vista:** la figura está **fijada al sexo registrado del paciente** (`patient_data.sex`): una paciente femenina ve el diagrama femenino y el resto el masculino. No es seleccionable, así que un enlace antiguo con `?figure=…` se ignora. Sólo se elige la **Vista** (Anterior o Posterior), que abre en el panel con más datos en el rango seleccionado.
- **Modo:** Superposición o Mapa de Calor.
- **Métrica de Calor:** Máxima (predeterminada), Promedio o Recuento.

**Modo Superposición** dibuja las marcas de las visitas seleccionadas sobre una misma figura, con un color distinto por visita (paleta Okabe–Ito) y una leyenda que asocia cada color con la fecha de su visita. Se pueden superponer hasta **5** visitas; la página preselecciona las **3 más recientes** y el navegador impide marcar una sexta. Un campo oculto `visits_present` distingue "ninguna visita seleccionada" de "no enviado", de modo que vaciar la selección se respeta en lugar de volver a los valores predeterminados.

**Modo Mapa de Calor** colorea cada zona anatómica según la métrica activa. Como una zona puede marcarse varias veces en una misma visita, el valor de calor siempre se deriva de **un valor por zona y visita**: la intensidad más alta registrada para esa zona en esa visita:

- **Máxima:** el valor más alto entre las visitas del rango (paleta 0–10, los mismos colores del formulario).
- **Promedio:** la media de los valores por visita.
- **Recuento:** el número de visitas distintas en las que se marcó la zona (escala secuencial de claro a oscuro).

Ambos modos acompañan una **tabla de totales por visita** (fecha, marcas, máxima, promedio) y, en mapa de calor, una **tabla de calor por zona** ordenada por la métrica activa. Cada cifra está acotada al panel de figura y vista seleccionado. Al pasar el cursor sobre una zona se muestra un globo con sus visitas, marcas, máxima, promedio y última visita. La disposición se imprime correctamente con el botón **Imprimir**.

**Zoom y desplazamiento.** El mapa anatómico admite la misma navegación que el formulario de carga: **zoom con la rueda del ratón** centrado en el cursor (1×–5×), **arrastre con el botón derecho** para desplazar, botones flotantes **+ / 100% / −** y gestos táctiles (pellizcar para acercar, arrastrar para desplazar cuando hay zoom). Los controles se ocultan al imprimir, y cualquier cambio de filtro (visita, rango, vista o modo) recarga la página con el mapa de vuelta al 100%, ya que el estado de zoom no se persiste a propósito.

**Impresión.** La leyenda de calor/intensidad se dibuja como **SVG en línea** (`pz_heatLegendSvg()`) en lugar de un degradado CSS, porque los navegadores descartan los fondos y degradados al imprimir a PDF — eso dejaba la leyenda como un rectángulo vacío con borde. Los rellenos del SVG son contenido y siempre se imprimen.

Accesos directos: el botón **Comparar Visitas** en la barra de acciones del formulario y el enlace **Comparar con visitas anteriores** en el reporte del encuentro. Ambos cargan la página dentro de la pestaña del encuentro actual (el mismo patrón que usa el módulo de asistente con IA), de modo que se conserva el diseño de pestañas de OpenEMR. Un botón **Volver** regresa al formulario de Pain Zones desde el que se abrió la página — o, si se abrió desde el reporte del encuentro, a la lista de formularios del encuentro.

---
## 🖨️ Reportes del Encuentro

El archivo `report.php` define la función `pain_zones_report()`. Genera una tabla HTML limpia con el detalle de las marcas (zona, lateralidad, intensidad, cualidad, notas), la cual OpenEMR integra de forma estándar en el historial del encuentro y en el resumen imprimible de la consulta médica.

**Figura del cuerpo.** El reporte en pantalla muestra **solo la tabla de marcas** — no se dibuja ninguna figura fija. Al pasar el mouse por una fila se abre una **ventana flotante** junto al cursor con la figura del cuerpo y el detalle clínico de esa marca. La figura es el **panel representativo** del formulario (la figura + vista que concentra más marcas; en caso de empate, prioriza Adulto Masculino / Anterior), con sus zonas anatómicas teñidas por intensidad y un pin por marca, reutilizando la misma lógica de color que el mapa de calor de la comparación. Dentro de la ventana, la **zona y el pin de la fila apuntada se resaltan** mientras el resto se atenúa; las líneas clínicas muestran zona, lateralidad, un badge de intensidad, rango, cualidad, irradiación, inicio, duración, factores agravantes/atenuantes y notas.

- **En la exportación a PDF** la figura se embebe junto a la tabla de marcas como `<img src="data:image/svg+xml;base64,…">`. Esto es intencional: mPDF solo dibuja el SVG cuando se referencia como **imagen** (`<img>`/`<input>`/`<meter>`); un elemento `<svg>` inline se ignora en silencio. El SVG se sanea (`pz_sanitizeSvgForPdf()`) — sin metadatos, filtros ni namespaces del editor — de modo que se renderiza sin archivos temporales.

La bifurcación PDF/HTML se decide con `$GLOBALS['PDF_OUTPUT']`, que OpenEMR define en `custom_report.php` para las exportaciones imprimibles; el sumario del encuentro (`forms.php`) no lo define.

Por otro lado, el resumen clínico editable en texto plano almacenado en `summary_text` (autocompletado por el formulario, con `pz_buildSummary()` del servidor como respaldo) ofrece un digesto compacto y legible de las marcas, pensado como contexto para la nota SOAP. No es lo mismo que el reporte tabular.

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
