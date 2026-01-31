# Guía de OGameX - Proyecto y Entorno Docker

## Información del Proyecto

OGameX es un clon de OGame escrito en Laravel 12 (PHP 8.5). Es un juego de estrategia espacial donde los jugadores construyen imperios, investigan tecnologías y compiten en batallas espaciales.

### Stack Tecnológico

- **Framework:** Laravel 12
- **PHP:** 8.5
- **Base de Datos:** MariaDB 11.3.2
- **Servidor Web:** Nginx (Alpine)
- **Battle Engine:** Rust (FFI integration)
- **Contenedores:** Docker Compose

### Estructura del Proyecto

```
OGameX/
├── app/                    # Código de la aplicación (Namespace: OGame\)
│   ├── Http/Controllers/   # Controladores
│   ├── Models/             # Modelos Eloquent
│   ├── Services/           # Lógica de negocio
│   ├── Enums/              # Enumeraciones PHP
│   └── Jobs/               # Jobs de cola
├── database/               # Migraciones y seeders
├── resources/              # Vistas Blade y assets
├── routes/                 # Rutas (web.php, api.php)
├── rust/                   # Código Rust del Battle Engine
├── public/                 # Archivos públicos
├── storage/                # Archivos de almacenamiento
├── docker-compose.yml      # Configuración Docker
├── Dockerfile              # Imagen PHP custom
└── .env                    # Variables de entorno
```

---

## Docker: Servicios y Contenedores

### Contenedores Activos

| Servicio | Nombre del Contenedor | Rol |
|----------|----------------------|-----|
| PHP-FPM | `ogamex-app` | Aplicación Laravel |
| Nginx | `ogamex-webserver` | Servidor web |
| MariaDB | `ogamex-db` | Base de datos |
| Queue Worker | `ogamex-queue-worker` | Procesamiento de colas |
| Scheduler | `ogamex-scheduler` | Tareas programadas |
| PhpMyAdmin | `ogamex-phpmyadmin` | Admin de BD (puerto 8080) |

### Variables de Entorno de Docker

```bash
HTTP_PORT=80           # Puerto HTTP (default: 80)
HTTPS_PORT=443         # Puerto HTTPS (default: 443)
PHPMYADMIN_PORT=8080   # Puerto PhpMyAdmin (default: 8080)
DB_EXTERNAL_PORT=3306  # Puerto MySQL externo (default: 3306)
```

---

## CÓMO EJECUTAR PHP EN ESTE ENTORNO

### Método 1: Docker Compose (RECOMENDADO)

Este es el método principal para ejecutar comandos PHP artisan:

```bash
# Ejecutar comandos artisan
docker compose exec ogamex-app php artisan <comando>

# Ejemplos:
docker compose exec ogamex-app php artisan queue:work --stop-when-empty
docker compose exec ogamex-app php artisan migrate
docker compose exec ogamex-app php artisan tinker
docker compose exec ogamex-app php artisan cache:clear
docker compose exec ogamex-app php artisan config:clear
```

### Método 2: Laravel Sail

El proyecto tiene Sail instalado como dependencia dev. Puedes usarlo:

```bash
# Ejecutar comandos con Sail
./vendor/bin/sail artisan <comando>

# Ejemplos:
./vendor/bin/sail artisan queue:work --stop-when-empty
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan tinker
```

**Nota:** Sail utiliza la misma configuración Docker que docker-compose.yml.

### Método 3: Entrar al Contenedor (Shell Interactivo)

Para ejecutar múltiples comandos PHP sin escribir el prefijo cada vez:

```bash
# Entrar al contenedor app
docker compose exec ogamex-app bash

# Una vez dentro, PHP está disponible directamente
php artisan queue:work --stop-when-empty
php artisan migrate:fresh --seed
php artisan tinker
composer dump-autoload

# Para salir
exit
```

### Método 4: Ruta Directa a PHP (Dentro del contenedor)

```bash
# Ruta de PHP dentro del contenedor
/usr/local/bin/php

# Ejemplo completo
docker compose exec ogamex-app /usr/local/bin/php artisan queue:work
```

---

## CÓMO REINICIAR EL SERVIDOR

### Opción 1: Reiniciar Contenedor Nginx (Recomendado)

El servidor web es Nginx en el contenedor `ogamex-webserver`:

```bash
# Reiniciar solo el servidor web
docker compose restart ogamex-webserver

# O reiniciar app y webserver (más completo)
docker compose restart ogamex-app ogamex-webserver
```

### Opción 2: php artisan serve (No usar en Docker)

**NO USES `php artisan serve` en este entorno Docker.** Ya hay Nginx configurado.

Si necesitas recargar cambios de código Laravel:

```bash
# Limpiar cachés
docker compose exec ogamex-app php artisan cache:clear
docker compose exec ogamex-app php artisan config:clear
docker compose exec ogamex-app php artisan route:clear
docker compose exec ogamex-app php artisan view:clear
```

### Opción 3: Reiniciar Todos los Contenedores

```bash
# Reiniciar todo el stack
docker compose restart

# O detener y volver a iniciar
docker compose down
docker compose up -d
```

### Recargar Configuración de Nginx

```bash
# Verificar configuración de Nginx
docker compose exec ogamex-webserver nginx -t

# Recargar Nginx sin downtime
docker compose exec ogamex-webserver nginx -s reload
```

---

## COMANDOS FRECUENTES

### Base de Datos

```bash
# Ejecutar migraciones
docker compose exec ogamex-app php artisan migrate

# Fresh migration con seeders
docker compose exec ogamex-app php artisan migrate:fresh --seed

# Crear nueva migración
docker compose exec ogamex-app php artisan make:migration create_table_name

# Rollback de migraciones
docker compose exec ogamex-app php artisan migrate:rollback
```

### Colas (Queue Workers)

```bash
# Procesar colas hasta vaciar (recomendado para desarrollo)
docker compose exec ogamex-app php artisan queue:work --stop-when-empty

# Procesar colas en modo daemon (producción)
docker compose exec ogamex-app php artisan queue:work

# Ver jobs fallidos
docker compose exec ogamex-app php artisan queue:failed

# Reintentar jobs fallidos
docker compose exec ogamex-app php artisan queue:retry all
```

**Nota:** El contenedor `ogamex-queue-worker` ya ejecuta automáticamente `queue:work` en producción.

### Composer

```bash
# Instalar dependencias
docker compose exec ogamex-app composer install

# Añadir paquete
docker compose exec ogamex-app composer require vendor/package

# Actualizar dependencias
docker compose exec ogamex-app composer update

# Dump autoload
docker compose exec ogamex-app composer dump-autoload
```

### Tinker (Consola Interactiva)

```bash
# Entrar a Tinker
docker compose exec ogamex-app php artisan tinker

# Ejemplos en Tinker:
$user = \OGame\Models\User::first();
$user->dark_matter;
```

### Tests

```bash
# Ejecutar tests
docker compose exec ogamex-app php artisan test

# Ejecutar tests específicos
docker compose exec ogamex-app php artisan test --filter TestName

# Con cobertura
docker compose exec ogamex-app php artisan test --coverage
```

### Clear Cache

```bash
# Limpiar toda la caché
docker compose exec ogamex-app php artisan optimize:clear

# O individualmente:
docker compose exec ogamex-app php artisan cache:clear
docker compose exec ogamex-app php artisan config:clear
docker compose exec ogamex-app php artisan route:clear
docker compose exec ogamex-app php artisan view:clear
```

---

## URLs Y PUERTOS

| Servicio | URL |
|----------|-----|
| Aplicación Web | http://localhost |
| PhpMyAdmin | http://localhost:8080 |
| Base de Datos | localhost:3306 |

---

## DESARROLLO

### Ver Logs

```bash
# Logs de Laravel
docker compose logs -f ogamex-app

# Logs de Nginx
docker compose logs -f ogamex-webserver

# Logs de Queue Worker
docker compose logs -f ogamex-queue-worker

# Logs de todos los servicios
docker compose logs -f
```

### Permisos de Storage

Si tienes problemas de permisos:

```bash
# Desde el host (fuera de Docker)
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### Debugging

La aplicación tiene DebugBar instalada. Para activarla:

```bash
# En .env
DEBUGBAR_ENABLED=true
DEBUGBAR_REMOTE_SITES_PATH=/var/www
DEBUGBAR_LOCAL_SITES_PATH=/home/kroryan/OGameX
```

---

## RUST BATTLE ENGINE

El proyecto incluye un Battle Engine en Rust que se comunica con PHP via FFI.

### Recompilar Rust (si modificas rust/)

```bash
# Entrar al contenedor
docker compose exec ogamex-app bash

# Recompilar
cd /var/www/rust
cargo build --release
```

---

## TRUCOS ÚTILES

### Acceso rápido a PHP (alias)

Agrega a tu `~/.bashrc` o `~/.zshrc`:

```bash
alias ogamex='docker compose exec ogamex-app'
alias ogamex-php='docker compose exec ogamex-app php'
alias ogamex-artisan='docker compose exec ogamex-app php artisan'
alias ogamex-composer='docker compose exec ogamex-app composer'
```

Uso:
```bash
ogamex-artisan queue:work --stop-when-empty
ogamex-composer install
```

### Ver todos los contenedores corriendo

```bash
docker compose ps
```

### Ver recursos consumidos

```bash
docker stats
```

---

## SOLUCIÓN DE PROBLEMAS COMUNES

### "php: command not found"

**Causa:** Estás ejecutando PHP desde el host, no dentro del contenedor.

**Solución:**
```bash
# INCORRECTO
php artisan queue:work

# CORRECTO
docker compose exec ogamex-app php artisan queue:work
```

### La aplicación no responde

```bash
# Verificar que todos los contenedores están corriendo
docker compose ps

# Reiniciar los servicios
docker compose restart
```

### Error de conexión a base de datos

```bash
# Verificar que el contenedor de BD está corriendo
docker compose ps ogamex-db

# Ver logs de BD
docker compose logs ogamex-db
```

### Las colas no se procesan

```bash
# Verificar estado del queue worker
docker compose ps ogamex-queue-worker

# Ver logs del queue worker
docker compose logs ogamex-queue-worker

# Ejecutar manualmente para debug
docker compose exec ogamex-app php artisan queue:work --timeout=60
```

---

## BOTS (IA) - NOTAS RÁPIDAS

- **Ciclo por defecto:** si no hay `activity_schedule`, los bots juegan 20 minutos cada 4 horas.
- Configurable en `config/bots.php`:
  - `default_activity_cycle_minutes`
  - `default_activity_window_minutes`
- El scheduler ejecuta `ogamex:scheduler:process-bots` cada 5 minutos.
- Para acelerar desarrollo, puedes reducir `min_resources_for_actions` desde el panel de edición de bots.

---

## SUMMARY RÁPIDO (Para otra IA)

| Tarea | Comando |
|-------|---------|
| Ejecutar PHP | `docker compose exec ogamex-app php <comando>` |
| Ejecutar Artisan | `docker compose exec ogamex-app php artisan <comando>` |
| Ejecutar Composer | `docker compose exec ogamex-app composer <comando>` |
| Reiniciar servidor | `docker compose restart ogamex-webserver` |
| Reiniciar todo | `docker compose restart` |
| Ver logs | `docker compose logs -f <servicio>` |
| Queue worker | `docker compose exec ogamex-app php artisan queue:work --stop-when-empty` |
| Entrar al contenedor | `docker compose exec ogamex-app bash` |

Servicios Docker: `ogamex-app`, `ogamex-webserver`, `ogamex-db`, `ogamex-queue-worker`, `ogamex-scheduler`, `ogamex-phpmyadmin`
