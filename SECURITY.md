# 🔒 Guía de Seguridad - Sistema de Validación de Facturas DIAN

## ✅ Mejoras de Seguridad Implementadas

### 1. **Variables de Entorno (.env)**
- ✅ Las credenciales de base de datos ahora están en `.env`
- ✅ Este archivo NUNCA debe subirse a Git
- ✅ Use `.env.example` como plantilla

### 2. **Protección de Archivos (.htaccess)**
- ✅ Bloqueo de acceso a archivos sensibles (.env, .log, .sql)
- ✅ Prevención de directory listing
- ✅ Headers de seguridad (XSS, Clickjacking, MIME sniffing)
- ✅ Solo archivos específicos son accesibles públicamente

### 3. **Sanitización y Validación**
- ✅ Todos los inputs son sanitizados antes de procesarse
- ✅ Validación de tipos de datos (int, date, url, host)
- ✅ Prevención de inyección SQL usando prepared statements
- ✅ Escape de HTML para prevenir XSS

### 4. **Headers de Seguridad HTTP**
- ✅ X-Content-Type-Options: nosniff
- ✅ X-Frame-Options: DENY
- ✅ X-XSS-Protection: 1; mode=block
- ✅ CORS configurado

### 5. **Control de Errores**
- ✅ Errores detallados solo en modo debug
- ✅ Logs de errores en carpeta protegida
- ✅ Mensajes genéricos en producción

### 6. **Git Seguro (.gitignore)**
- ✅ Excluye archivos sensibles
- ✅ Excluye logs y backups
- ✅ Excluye configuraciones locales

## 📋 Checklist de Configuración

### Configuración Inicial:
1. ✅ Copie `.env.example` como `.env`
2. ✅ Configure las credenciales en `.env`
3. ✅ Verifique que `.env` NO esté en Git
4. ✅ Configure permisos de carpetas:
   ```bash
   chmod 755 /ruta/a/factura_dian_descargar
   chmod 600 .env
   chmod 755 logs
   ```

### Antes de Producción:
1. ⬜ Cambie `APP_DEBUG=false` en `.env`
2. ⬜ Configure `APP_ENV=production` en `.env`
3. ⬜ Use HTTPS en el servidor
4. ⬜ Configure firewall para limitar acceso al puerto MySQL
5. ⬜ Implemente rate limiting en el servidor
6. ⬜ Configure backups automáticos de la BD
7. ⬜ Use un usuario de BD con permisos limitados (no root)

## 🚨 Recomendaciones Adicionales

### Base de Datos:
- Crear un usuario específico para la aplicación con permisos mínimos:
  ```sql
  CREATE USER 'app_user'@'%' IDENTIFIED BY 'contraseña_segura';
  GRANT SELECT, UPDATE ON apidian.documents TO 'app_user'@'%';
  FLUSH PRIVILEGES;
  ```

### Servidor Web:
- Configurar SSL/TLS
- Implementar fail2ban para prevenir ataques de fuerza bruta
- Configurar mod_security en Apache
- Limitar el tamaño de uploads

### Monitoreo:
- Revisar logs regularmente: `/logs/errors.log`
- Configurar alertas para errores críticos
- Monitorear intentos de acceso no autorizado

### Actualizaciones:
- Mantener PHP actualizado
- Actualizar dependencias regularmente
- Aplicar parches de seguridad

## 📞 Contacto de Seguridad

Si encuentra una vulnerabilidad de seguridad:
1. NO la publique públicamente
2. Envíe un reporte detallado al administrador
3. Incluya pasos para reproducir el problema

## 📊 Niveles de Acceso

| Archivo/Carpeta | Acceso Web | Permisos |
|----------------|------------|----------|
| .env | ❌ Bloqueado | 600 |
| config.php | ❌ Bloqueado | 644 |
| logs/ | ❌ Bloqueado | 755 |
| *.html | ✅ Permitido | 644 |
| procesar_duplicados.php | ✅ Permitido | 644 |
| api.php | ✅ Permitido | 644 |

## 🔄 Rotación de Credenciales

Se recomienda rotar las credenciales cada:
- Contraseñas de BD: 90 días
- Tokens de API: 30 días
- Revisar accesos: Mensualmente

---

**Última actualización:** <?= date('Y-m-d') ?>
**Versión de seguridad:** 1.0