# 📊 duplicados_apidian_control_pos

Sistema para validar y corregir facturas duplicadas en la base de datos, comparando con los datos oficiales de la DIAN.

## 🎯 Descripción

Este sistema identifica facturas con CUFE duplicado en la base de datos y las valida contra la información oficial de la DIAN. Cuando encuentra duplicados, compara los valores (subtotal y total) con la DIAN y actualiza el estado de las facturas que no coinciden.

## ✨ Características

- 🔍 Búsqueda automática de documentos con CUFE duplicado
- 🌐 Validación en tiempo real con la API de la DIAN
- ✅ Comparación de valores (subtotal y total)
- 🔄 Actualización automática del estado de documentos incorrectos
- 🛡️ Modo prueba para proteger la base de datos de producción
- 📈 Interfaz web intuitiva con estadísticas en tiempo real
- 🔒 Seguridad mejorada con variables de entorno

## 📋 Requisitos

- PHP 7.4 o superior
- MySQL 5.7 o superior
- Extensiones PHP: PDO, PDO_MySQL, CURL
- Apache con mod_rewrite habilitado

## 🚀 Instalación

1. **Clonar el repositorio:**
   ```bash
   git clone https://github.com/csaenzs/duplicados_apidian_control_pos.git
   cd duplicados_apidian_control_pos
   ```

2. **Configurar variables de entorno:**
   ```bash
   cp .env.example .env
   ```
   Editar `.env` con las credenciales de tu base de datos:
   ```
   DB_HOST=tu_servidor
   DB_PORT=3307
   DB_DATABASE=apidian
   DB_USERNAME=tu_usuario
   DB_PASSWORD=tu_contraseña
   ```

3. **Configurar permisos:**
   ```bash
   chmod 600 .env
   chmod 755 logs/
   ```

4. **Verificar la configuración:**
   Abrir en el navegador: `http://tu-servidor/test_conexion.php`

## 📖 Uso

1. **Acceder a la interfaz web:**
   ```
   http://tu-servidor/validar_duplicados.html
   ```

2. **Completar el formulario:**
   - **Número de Identificación**: NIT o CC a consultar
   - **Rango de fechas**: Período a validar
   - **URL Token DIAN**: Token de autenticación de la DIAN
   - **Límite de registros**: Cantidad de registros a procesar (modo prueba)

3. **Ejecutar validación:**
   - Click en "Buscar y Validar Duplicados"
   - El sistema mostrará el progreso y resultados

## 🔄 Flujo del Sistema

1. Busca documentos con CUFE duplicado en la base de datos
2. Para cada grupo de duplicados:
   - Obtiene información de la DIAN usando el CUFE como trackId
   - Compara valores (subtotal y total) con cada documento
   - El documento que coincide mantiene `state_document_id = 1`
   - Los documentos que no coinciden se actualizan a `state_document_id = 0`

## 📊 Estructura de la Base de Datos

La tabla `documents` debe contener al menos:
- `id`: Identificador único
- `identification_number`: NIT/CC del emisor
- `state_document_id`: Estado del documento (1=activo, 0=inactivo)
- `prefix`: Prefijo de la factura
- `number`: Número de la factura
- `cufe`: Código Único de Factura Electrónica
- `subtotal`: Subtotal de la factura
- `total`: Total de la factura
- `created_at`: Fecha de creación

## 🛡️ Seguridad

- ✅ Credenciales en variables de entorno (.env)
- ✅ Validación y sanitización de inputs
- ✅ Protección contra inyección SQL
- ✅ Headers de seguridad HTTP
- ✅ Archivos sensibles protegidos con .htaccess
- ✅ Logs en carpeta protegida

Ver [SECURITY.md](SECURITY.md) para más detalles.

## 📁 Estructura del Proyecto

```
factura_dian_descargar/
├── .env                    # Variables de entorno (no en Git)
├── .env.example           # Plantilla de variables
├── .htaccess             # Configuración de seguridad Apache
├── .gitignore           # Archivos excluidos de Git
├── config.php           # Cargador de configuración
├── validar_duplicados.html  # Interfaz de usuario
├── procesar_duplicados.php  # Lógica principal
├── api.php              # API de conexión con DIAN
├── test_conexion.php    # Prueba de conexión BD
├── logs/               # Carpeta de logs
├── cookies/           # Carpeta de cookies de sesión
└── README.md         # Este archivo
```

## 🐛 Solución de Problemas

### Error de conexión a base de datos:
- Verificar credenciales en `.env`
- Verificar puerto (generalmente 3306 o 3307)
- Verificar que el usuario tenga permisos remotos

### No encuentra datos de DIAN:
- Verificar que el token URL sea válido
- Verificar conexión a internet
- Revisar logs en `logs/errors.log`

### Modo prueba:
- Por defecto procesa solo 2 registros
- Aumentar o eliminar límite para procesar todos

## 📝 Licencia

Este proyecto es privado y propietario.

## 👤 Autor

- **GitHub**: [@csaenzs](https://github.com/csaenzs)

## 📞 Soporte

Para soporte o preguntas, crear un issue en el repositorio.

---

**Última actualización:** Octubre 2025