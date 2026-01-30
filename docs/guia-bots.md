# Guía del Sistema de Playerbots - OGameX

## Índice
1. [Introducción](#introducción)
2. [Instalación y Configuración](#instalación-y-configuración)
3. [Comandos del Sistema](#comandos-del-sistema)
4. [Panel de Administración](#panel-de-administración)
5. [Configuración de Bots](#configuración-de-bots)
6. [Solución de Problemas](#solución-de-problemas)

---

## Introducción

El Sistema de Playerbots es una funcionalidad avanzada de OGameX que permite crear jugadores automatizados (bots) que juegan de forma autónoma. Los bots pueden construir edificios, investigar tecnologías, construir flotas, enviar expediciones y atacar a otros jugadores (incluyendo otros bots).

### Características Principales

- **4 Personalidades**: Agresivo, Defensivo, Económico y Equilibrado
- **Decisiones Inteligentes**: Los bots priorizan acciones según su personalidad
- **Sistema de Objetivos**: Los bots pueden buscar objetivos aleatorios, débiles, ricos o similares
- **Configuración Flexible**: Ajusta horarios de actividad, probabilidades y comportamiento
- **Panel de Administración**: Monitorea y controla todos los bots desde la interfaz web
- **Sistema de Logs**: Registro completo de todas las acciones de los bots

---

## Instalación y Configuración

### Requisitos Previos

El sistema de bots requiere que las migraciones de la base de datos se hayan ejecutado correctamente:

```bash
docker compose exec -T ogamex-app php artisan migrate
```

### Verificar Instalación

Verifica que las tablas de bots existen:

```bash
docker compose exec -T ogamex-app php artisan tinker --execute="echo OGame\Models\Bot::count() . ' bots encontrados';"
```

---

## Comandos del Sistema

### Iniciar los Contenedores

```bash
# Iniciar todos los contenedores
docker compose up -d

# Ver estado de los contenedores
docker compose ps

# Ver logs en tiempo real
docker compose logs -f ogamex-app
```

### Reiniciar el Sistema

```bash
# Reiniciar todos los contenedores
docker compose restart

# Reiniciar solo la aplicación PHP
docker compose restart ogamex-app

# Reiniciar la base de datos
docker compose restart ogamex-db
```

### Detener los Contenedores

```bash
# Detener todos los contenedores
docker compose down

# Detener y eliminar volúmenes (cuidado: se pierden datos)
docker compose down -v
```

### Comandos del Scheduler de Bots

```bash
# Ejecutar el scheduler manualmente (procesa todos los bots activos)
docker compose exec -T ogamex-app php artisan ogamex:scheduler:process-bots

# Ver la configuración actual del scheduler
docker compose exec -T ogamex-app php artisan tinker --execute="var_dump(config('bots'));"
```

### Comandos de Mantenimiento

```bash
# Limpiar caché
docker compose exec -T ogamex-app php artisan cache:clear

# Limpiar caché de configuración
docker compose exec -T ogamex-app php artisan config:clear

# Limpiar caché de rutas
docker compose exec -T ogamex-app php artisan route:clear

# Optimizar la aplicación
docker compose exec -T ogamex-app php artisan optimize
```

### Acceder a la Consola de Laravel (Tinker)

```bash
# Iniciar tinker
docker compose exec -T ogamex-app php artisan tinker

# Ejecutar comando directamente
docker compose exec -T ogamex-app php artisan tinker --execute="echo 'Comando';"
```

### Consultas Útiles en Tinker

```php
// Ver todos los bots
OGame\Models\Bot::all()->each(fn($b) => echo $b->name . ' - ' . $b->personality . PHP_EOL);

// Ver logs recientes de un bot
$bot = OGame\Models\Bot::first();
$bot->logs()->take(10)->get()->each(fn($l) => echo $l->action_type . ': ' . $l->action_description . PHP_EOL);

// Activar/desactivar un bot
$bot = OGame\Models\Bot::first();
$bot->is_active = true;
$bot->save();

// Resetear cooldown de un bot
$bot = OGame\Models\Bot::first();
$bot->last_action_at = null;
$bot->save();

// Ver recursos de un bot
$factory = new OGame\Factories\PlayerServiceFactory();
$player = $factory->make(OGame\Models\Bot::first()->user_id);
$planet = $player->planets->first();
echo $planet->getResources()->metal->get();
```

---

## Panel de Administración

### Acceder al Panel

1. Inicia sesión como administrador
2. Navega a `https://tu-dominio.com/admin/bots`

### Funcionalidades del Panel

#### Lista de Bots (`/admin/bots`)
- Ver todos los bots existentes
- Estado (activo/inactivo)
- Última acción y cooldown
- Acciones rápidas:
  - Editar configuración
  - Activar/Desactivar
  - Ver logs
  - Eliminar bot
  - Forzar acción (build/fleet/research/attack)

#### Crear Bot (`/admin/bots/create`)
- Nombre del bot
- Personalidad (Agresivo/Defensivo/Económico/Equilibrado)
- Tipo de objetivo preferido
- Estado inicial

#### Editar Bot (`/admin/bots/edit/{id}`)
- Toda la configuración del bot
- Ajustes avanzados:
  - **Horario de Actividad**: Define horas y días específicos
  - **Probabilidades de Acción**: Personaliza pesos para cada acción
  - **Configuración Económica**: Ajusta gestión de recursos
  - **Configuración de Flota**: Controla composición y tamaño
  - **Flags de Comportamiento**: Habilita/deshabilita acciones

#### Ver Logs (`/admin/bots/logs/{id}`)
- Historial completo de acciones
- Filtrado por tipo de acción
- Recursos gastados
- Resultado de cada acción

#### Estadísticas (`/admin/bots/stats`)
- Resumen global de actividad
- Distribución de acciones por tipo
- Bots más activos
- Acciones recientes

---

## Configuración de Bots

### Personalidades y Comportamientos

| Personalidad | Construcción | Flota | Ataque | Investigación | Estrategia |
|--------------|--------------|-------|--------|---------------|------------|
| **AGGRESSIVE** | 20% | 35% | 35% | 10% | Enfocado en combate y flotas poderosas |
| **DEFENSIVE** | 40% | 25% | 10% | 25% | Enfocado en defensas y producción |
| **ECONOMIC** | 50% | 15% | 5% | 30% | Enfocado en producción e investigación |
| **BALANCED** | 30% | 25% | 20% | 25% | Equilibrado en todos los aspectos |

### Tipos de Objetivos

- **RANDOM**: Ataca a cualquier jugador disponible
- **WEAK**: Prioriza jugadores con menos poder militar
- **RICH**: Prioriza jugadores con más recursos
- **SIMILAR**: Prioriza jugadores con poder similar

### Configuración Avanzada (JSON)

#### activity_schedule (Horario de Actividad)
```json
{
  "active_hours": [8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20],
  "inactive_days": ["saturday", "sunday"]
}
```
- `active_hours`: Array de horas (0-23) en las que el bot está activo
- `inactive_days`: Array de días en los que el bot no actúa

#### action_probabilities (Probabilidades de Acción)
```json
{
  "build": 30,
  "fleet": 25,
  "attack": 20,
  "research": 25
}
```
Sobrescribe las probabilidades por defecto de la personalidad.

#### economy_settings (Configuración Económica)
```json
{
  "save_for_upgrade_percent": 0.3,
  "min_resources_for_actions": 10000,
  "max_storage_before_spending": 0.9,
  "prioritize_production": "balanced"
}
```
- `save_for_upgrade_percent`: Porcentaje de producción guardada (0.0-1.0)
- `min_resources_for_actions`: Recursos mínimos para actuar
- `max_storage_before_spending`: Máximo uso de almacenador antes de gastar
- `prioritize_production`: 'balanced', 'metal', 'crystal', o 'deuterium'

#### fleet_settings (Configuración de Flota)
```json
{
  "attack_fleet_percentage": 0.7,
  "expedition_fleet_percentage": 0.3,
  "min_fleet_size_for_attack": 100,
  "prefer_fast_ships": false,
  "always_include_recyclers": true,
  "max_expedition_fleet_cost_percentage": 0.2
}
```
- `attack_fleet_percentage`: Porcentaje de flota enviada en ataques
- `expedition_fleet_percentage`: Porcentaje de flota enviada en expediciones
- `min_fleet_size_for_attack`: Puntos mínimos de flota para atacar
- `prefer_fast_ships`: Preferir naves rápidas sobre potentes
- `always_include_recyclers`: Incluir recicladores en ataques
- `max_expedition_fleet_cost_percentage`: Máximo valor de flota para expediciones

#### behavior_flags (Flags de Comportamiento)
```json
{
  "disabled_actions": ["trade", "expedition"],
  "min_resources_for_actions": 50000,
  "avoid_stronger_players": true,
  "max_planets_to_colonize": 5
}
```
- `disabled_actions`: Array de acciones deshabilitadas
- `min_resources_for_actions`: Recursos mínimos para cualquier acción
- `avoid_stronger_players`: Evitar jugadores más fuertes
- `max_planets_to_colonize`: Límite de planetas a colonizar

---

## Solución de Problemas

### El bot no realiza acciones

**Verificar que el bot esté activo:**
```bash
docker compose exec -T ogamex-app php artisan tinker --execute="
$bot = OGame\Models\Bot::first();
echo 'Activo: ' . ($bot->is_active ? 'Si' : 'No') . PHP_EOL;
echo 'Horario activo: ' . ($bot->isActive() ? 'Si' : 'No') . PHP_EOL;
"
```

**Verificar cooldown:**
```bash
docker compose exec -T ogamex-app php artisan tinker --execute="
$bot = OGame\Models\Bot::first();
echo 'Última acción: ' . $bot->last_action_at . PHP_EOL;
echo 'Cooldown hasta: ' . $bot->attack_cooldown_until . PHP_EOL;
echo 'Puede atacar: ' . ($bot->canAttack() ? 'Si' : 'No') . PHP_EOL;
"
```

**Resetear cooldown manual:**
```bash
docker compose exec -T ogamex-app php artisan tinker --execute="
$bot = OGame\Models\Bot::first();
$bot->last_action_at = null;
$bot->attack_cooldown_until = null;
$bot->save();
echo 'Cooldown reseteado';
"
```

### Errores de base de datos

**Reejecutar migraciones:**
```bash
docker compose exec -T ogamex-app php artisan migrate:fresh --seed
```

**Verificar estado de migraciones:**
```bash
docker compose exec -T ogamex-app php artisan migrate:status
```

### Errores de caché

**Limpiar toda la caché:**
```bash
docker compose exec -T ogamex-app php artisan cache:clear
docker compose exec -T ogamex-app php artisan config:clear
docker compose exec -T ogamex-app php artisan route:clear
docker compose exec -T ogamex-app php artisan view:clear
```

### El bot no tiene planetas

**Crear planeta para el bot:**
```bash
docker compose exec -T ogamex-app php artisan tinker --execute="
$bot = OGame\Models\Bot::first();
$factory = new OGame\Factories\PlanetServiceFactory();
$coord = new OGame\Models\Planet\Coordinate(1, 100, 5);
$planet = $factory->createAdditionalPlanetForPlayer(
    (new OGame\Factories\PlayerServiceFactory())->make($bot->user_id),
    $coord
);
$bot->user->planet_current = $planet->getPlanetId();
$bot->user->save();
echo 'Planeta creado';
"
```

### Problemas de recursos

**Dar recursos al bot:**
```bash
docker compose exec -T ogamex-app php artisan tinker --execute="
$factory = new OGame\Factories\PlayerServiceFactory();
$player = $factory->make(OGame\Models\Bot::first()->user_id);
$planet = $player->planets->first();
$planet->planet->metal = 100000;
$planet->planet->crystal = 100000;
$planet->planet->deuterium = 50000;
$planet->planet->save();
echo 'Recursos añadidos';
"
```

### Ver logs del sistema

**Logs de Laravel:**
```bash
docker compose exec -T ogamex-app tail -f storage/logs/laravel.log
```

**Logs de la aplicación:**
```bash
docker compose logs -f ogamex-app
```

**Logs de la base de datos:**
```bash
docker compose logs -f ogamex-db
```

### Configurar el Scheduler en Cron (Opcional)

Si deseas ejecutar el scheduler automáticamente cada X minutos, agrega al crontab del sistema:

```bash
# Editar crontab
crontab -e

# Agregar línea (ejecuta cada 5 minutos)
*/5 * * * * cd /ruta/a/OGameX && docker compose exec -T ogamex-app php artisan ogamex:scheduler:process-bots
```

### Puerto de base de datos en uso

Si tienes conflicto con el puerto 3306, asegúrate de usar el puerto 3307:

**En `.env`:**
```
DB_EXTERNAL_PORT=3307
```

**Reiniciar contenedores:**
```bash
docker compose down
docker compose up -d
```

---

## Configuración Global del Sistema

El archivo `config/bots.php` controla la configuración global:

```php
return [
    // Habilitar/deshabilitar el scheduler
    'scheduler_enabled' => env('BOTS_SCHEDULER_ENABLED', true),

    // Intervalo de ejecución del scheduler (minutos)
    'scheduler_interval_minutes' => env('BOTS_SCHEDULER_INTERVAL', 5),

    // Cooldown de ataque por defecto (horas)
    'default_attack_cooldown_hours' => 2,

    // Máximo de flotas simultáneas por bot
    'max_fleets_per_bot' => 3,

    // Probabilidad de expedición (0.0 - 1.0)
    'expedition_chance' => 0.15,
];
```

Modificar estos valores requiere limpiar la caché de configuración:

```bash
docker compose exec -T ogamex-app php artisan config:clear
```

---

## Contacto y Soporte

Para problemas o sugerencias sobre el sistema de bots, por favor abre un issue en el repositorio de GitHub.
