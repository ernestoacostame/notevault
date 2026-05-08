# NoteVault 📝

Gestor de notas ligero, seguro y responsive. PHP puro, sin base de datos.

## Características

- **Ligero**: HTML/CSS/JS puro + PHP backend, sin frameworks ni dependencias
- **6 Temas visuales**: Obsidian, Arctic, Forest, Midnight, Rosewood, Sand
- **Seguridad**: Autenticación con bcrypt (cost 12), protección contra fuerza bruta
- **Sin base de datos**: Todo se guarda en archivos JSON + texto plano
- **Sincronización**: Cambios guardados al instante en el servidor (escribe en móvil, ve en PC)
- **Modo offline**: Service Worker + PWA instalable en móvil
- **Markdown**: Editor con soporte completo y vista previa
- **Carpetas**: Organiza tus notas en carpetas personalizadas
- **Etiquetas**: Agrega #tags en línea y filtra por ellos
- **Buscador global**: Filtra por contenido y título mientras escribes
- **Copiar al portapapeles**: Un clic para copiar la nota completa
- **Auto-título**: La primera línea se convierte en el título automáticamente
- **Responsive**: Funciona perfecto en móvil, tablet y desktop

## Instalación Rápida

### 1. Requisitos
- Nginx
- PHP 8.0+ con php-fpm
- Módulos PHP: `json`, `session` (incluidos por defecto)

### 2. Copiar archivos

```bash
# Crear directorio
sudo mkdir -p /var/www/notevault

# Copiar archivos
sudo cp index.html api.php sw.js manifest.json /var/www/notevault/

# Crear directorio de datos con permisos correctos
sudo mkdir -p /var/www/notevault/data
sudo chown -R www-data:www-data /var/www/notevault/data
sudo chmod 700 /var/www/notevault/data
```

### 3. Configurar Nginx

```bash
# Copiar configuración
sudo cp nginx.conf /etc/nginx/sites-available/notevault

# Editar: ajustar server_name y root
sudo nano /etc/nginx/sites-available/notevault

# Activar
sudo ln -s /etc/nginx/sites-available/notevault /etc/nginx/sites-enabled/

# Verificar y recargar
sudo nginx -t
sudo systemctl reload nginx
```

### 4. HTTPS (Recomendado)

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d notas.tudominio.com
```

### 5. ¡Listo!

Abre `http://notas.tudominio.com` y crea tu cuenta.

## Estructura de Archivos

```
notevault/
├── index.html          # Frontend completo (SPA)
├── api.php             # Backend API REST
├── sw.js               # Service Worker (offline)
├── manifest.json       # PWA manifest
├── nginx.conf          # Config Nginx de ejemplo
├── README.md           # Este archivo
└── data/               # ← Creado automáticamente
    ├── users.json      # Credenciales (hashed)
    └── users/
        └── <usuario>/
            ├── notes.json    # Notas en JSON
            ├── folders.json  # Carpetas
            └── txt/          # Notas en texto plano
                ├── abc123.txt
                └── def456.txt
```

## Seguridad

- Contraseñas hasheadas con `bcrypt` (cost 12)
- Directorio `/data/` bloqueado por Nginx
- Delay aleatorio en login fallido (anti fuerza bruta)
- Session regeneration al hacer login
- Sanitización de nombres de usuario en filesystem
- Headers de seguridad (X-Frame-Options, X-Content-Type-Options, etc.)

## API Endpoints

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| POST | `api.php?action=register` | Crear cuenta |
| POST | `api.php?action=login` | Iniciar sesión |
| GET | `api.php?action=logout` | Cerrar sesión |
| GET | `api.php?action=session` | Verificar sesión |
| GET | `api.php?action=notes` | Listar notas |
| POST | `api.php?action=notes` | Crear nota |
| PUT | `api.php?action=notes` | Actualizar nota |
| DELETE | `api.php?action=notes&id=X` | Eliminar nota |
| GET | `api.php?action=folders` | Listar carpetas |
| POST | `api.php?action=folders` | Crear carpeta |
| DELETE | `api.php?action=folders&id=X` | Eliminar carpeta |
