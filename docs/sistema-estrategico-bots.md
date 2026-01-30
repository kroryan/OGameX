# Sistema Estratégico de Playerbots - Guía Técnica

## Índice
1. [Filosofía de Diseño](#filosofía-de-diseño)
2. [Arquitectura Estratégica](#arquitectura-estratégica)
3. [Sistema de Objetivos a Largo Plazo](#sistema-de-objetivos-a-largo-plazo)
4. [Proceso de Toma de Decisiones](#proceso-de-toma-de-decisiones)
5. [Prioridades por Fase del Juego](#prioridades-por-fase-del-juego)
6. [Implementación](#implementación)

---

## Filosofía de Diseño

El sistema estratégico de bots de OGameX está diseñado para simular jugadores humanos que toman decisiones inteligentes basadas en:
- **Objetivos a largo plazo**: No solo acciones inmediatas, sino planes progresivos
- **Análisis de situación**: Evaluar recursos, amenazas y oportunidades
- **Prioridades dinámicas**: Cambiar estrategia según la fase del juego
- **Eficiencia de recursos**: Maximizar el crecimiento sostenible

### Principios Fundamentales

1. **Crecimiento Sostenible**: Los bots equilibran producción invertida vs. producción futura
2. **Gestión de Riesgos**: Evaluar amenazas antes de expandirse
3. **Especialización Progresiva**: Comenzar equilibrado, especializarse según personalidad
4. **Adaptabilidad**: Cambiar estrategia según el entorno del universo

---

## Arquitectura Estratégica

```
┌─────────────────────────────────────────────────────────────────┐
│                  MOTOR ESTRATÉGICO DEL BOT                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │         1. ANALIZAR SITUACIÓN ACTUAL                    │  │
│  │  • Recursos disponibles y producción                    │  │
│  │  • Nivel de edificios y tecnologías                     │  │
│  │  • Poder militar (flotas + defensas)                    │  │
│  │  • Posición en el ranking                               │  │
│  │  • Amenazas y oportunidades                             │  │
│  └────────────────────┬─────────────────────────────────────┘  │
│                       │                                          │
│  ┌────────────────────▼─────────────────────────────────────┐  │
│  │         2. DETERMINAR FASE DEL JUEGO                    │  │
│  │  • Early Game: < 100k puntos                            │  │
│  │  • Mid Game: 100k - 1M puntos                           │  │
│  │  • Late Game: > 1M puntos                               │  │
│  └────────────────────┬─────────────────────────────────────┘  │
│                       │                                          │
│  ┌────────────────────▼─────────────────────────────────────┐  │
│  │         3. ESTABLECER OBJETIVO PRINCIPAL                │  │
│  │  Objetivos según fase y personalidad:                   │  │
│  │  • Economico: Maximizar producción                      │  │
│  │  • Agresivo: Acumular poder militar                     │  │
│  │  • Defensivo: Fortificar posición                       │  │
│  │  • Equilibrado: Crecimiento sostenible                  │  │
│  └────────────────────┬─────────────────────────────────────┘  │
│                       │                                          │
│  ┌────────────────────▼─────────────────────────────────────┐  │
│  │         4. EVALUAR OPCIONES DISPONIBLES                 │  │
│  │  • ¿Qué edificios puedo construir?                      │  │
│  │  • ¿Qué tecnologías investigar?                         │  │
│  │  • ¿Qué naves construir?                                │  │
│  │  • ¿Atacar o esperar?                                   │  │
│  └────────────────────┬─────────────────────────────────────┘  │
│                       │                                          │
│  ┌────────────────────▼─────────────────────────────────────┐  │
│  │         5. PRIORIZAR ACCIONES (SCORING)                 │  │
│  │  • Impacto en el objetivo principal                     │  │
│  │  • Costo vs. Beneficio                                  │  │
│  │  • Tiempo de retorno                                    │  │
│  │  • Riesgo involucrado                                   │  │
│  └────────────────────┬─────────────────────────────────────┘  │
│                       │                                          │
│  ┌────────────────────▼─────────────────────────────────────┐  │
│  │         6. EJECUTAR MEJOR OPCIÓN                        │  │
│  │  • Construir edificio prioritario                       │  │
│  │  • Investigar tecnología clave                          │  │
│  │  • Construir flota estratégica                          │  │
│  │  • Lanzar ataque rentable                               │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Sistema de Objetivos a Largo Plazo

### Definición de Objetivos

Cada bot tiene un objetivo principal que guía todas sus decisiones. Este objetivo cambia según:
1. **Fase del juego** (early/mid/late)
2. **Personalidad** del bot
3. **Situación actual** (amenazas, oportunidades)

### Tipos de Objetivos

#### 1. Crecimiento Económico (Economic/Early Game)
```
Objetivo: Maximizar producción de recursos

Prioridades:
- Minas de metal/crystal nivel 20-30
- Sintetizador de deuterio nivel 15-20
- Almacenes nivel óptimo para evitar pérdidas
- Planta de energía para soportar mines
- Tecnología de energía (para IPM y大兴)
- Tecnología de investigación (para reducir tiempos)

KPIs:
- Producción por hora > 100k/100k/50k
- Tiempo para llenar almacenes < 12 horas
```

#### 2. Acumulación de Flota (Aggressive/Mid Game)
```
Objetivo: Construir flota poderosa

Prioridades:
- Hangar nivel 8+ para naves grandes
- Tecnología de combustion/impulse/hyperspace
- Naves clave según objetivo:
  • Cazador ligero: Número y velocidad
  • Cazador pesado: Poder de fuego
  • Crucero: Anti-cazador ligero
  • Nave de batalla: Equilibrio
  • Destructor/Acorazado: Poder masivo
- Tecnología de armas/armaduras/escudos

KPIs:
- Puntos de flota > 200k
- Capacidad de carga > 500k
- Velocidad de flota > 5.000
```

#### 3. Fortificación Defensiva (Defensive/All Phases)
```
Objetivo: Hacer ataques no rentables

Prioridades:
- Defensas en todos los planetas:
  • Lanzamisiles: Carnada
  • Láser ligero: Anti-caza ligero
  • Láser pesado: Anti-caza pesado
  • Cañón Gauss: Anti-crucero
  • Cañón iónico: Protección eficiente
  • Láser de batalla: Anti-nave grande
  • Misil interplanetario: Ofensiva
- Tecnología de escudo/armadura
- Campos de minas para ralentizar

KPIs:
- Coste de defender > 2x coste de atacar
- Defensas > 100k puntos por planeta
```

#### 4. Expansión Territorial (Balanced/Mid-Late Game)
```
Objetivo: Colonizar nuevos planetas

Prioridades:
- Tecnología de astrofísica (nivel mínimo para colonizar)
- Naves de colonización (2-3 mínimo)
- Ubicaciones estratégicas:
  • Posiciones 4-6 para planetas grandes
  • Sistemas solares dispersos
  • Near/debris fields para reciclaje
- Desarrollar planetas nuevos rápidamente

KPIs:
- 8-9 planetas colonizados
- Todos los planetas con mines nivel 15+
```

---

## Proceso de Toma de Decisiones

### Algoritmo de Decisión

```
FUNCIÓN decidirAccion(bot):
    situacion = analizarSituacion(bot)
    fase = determinarFaseJuego(bot.puntos)
    objetivo = getObjetivoPrincipal(bot.personalidad, fase)
    opciones = getOpcionesDisponibles(bot, situacion)

    mejor_opcion = null
    mejor_puntuacion = -infinito

    PARA CADA opcion EN opciones:
        puntuacion = evaluarOpcion(opcion, objetivo, situacion)

        // Modificadores estratégicos
        puntuacion *= getModificadorFase(fase, opcion.tipo)
        puntuacion *= getModificadorPersonalidad(bot.personalidad, opcion)
        puntuacion *= getModificadorSituacion(situacion, opcion)

        SI puntuacion > mejor_puntuacion:
            mejor_puntuacion = puntuacion
            mejor_opcion = opcion

    RETORNAR mejor_opcion
```

### Función de Evaluación de Opciones

```
FUNCIÓN evaluarOpcion(opcion, objetivo, situacion):
    puntuacion = 0

    // 1. Impacto en el objetivo principal (0-100 puntos)
    puntuacion += calcularImpactoObjetivo(opcion, objetivo)

    // 2. Eficiencia de retorno (0-100 puntos)
    ROI = calcularROI(opcion)
    puntuacion += ROI * 50

    // 3. Urgencia/temporización (0-50 puntos)
    urgencia = calcularUrgencia(opcion, situacion)
    puntuacion += urgencia

    // 4. Riesgo involucrado (-100 a 0 puntos)
    riesgo = calcularRiesgo(opcion, situacion)
    puntuacion -= riesgo

    // 5. Sinergia con otras acciones (0-30 puntos)
    sinergia = calcularSinergia(opcion, situacion)
    puntuacion += sinergia

    RETORNAR max(0, puntuacion)
```

---

## Prioridades por Fase del Juego

### Early Game (0 - 100k puntos)

**Meta:** Desarrollar economía base

```
ORDEN DE PRIORIDAD:
1. Minas (Metal > Crystal > Deuterio)
   - Metal mine hasta nivel 20
   - Crystal mine hasta nivel 18
   - Deuterium synth hasta nivel 15

2. Energía
   - Solar plant hasta nivel 20
   - Fusion reactor cuando tenga deuterio de sobra

3. Almacenes
   - Metal/Crystal store hasta nivel 8
   - Deuterium store hasta nivel 6

4. Infraestructura
   - Robot factory nivel 10
   - Shipyard nivel 7
   - Research lab nivel 8
   - Nanite factory (más tarde)

5. Tecnologías tempranas
   - Energy tech nivel 5
   - Laser tech nivel 5
   - Combustion drive nivel 6

NO HACER:
- Construir flota grande (solo defensas básicas)
- Colonizar (esperar a mid game)
- Atacar (esperar a tener flota)
```

### Mid Game (100k - 1M puntos)

**Meta:** Especializarse según personalidad

```
ECONÓMICO:
- Continuar desarrollando minas (25+/23+/20+)
- Construir nanite factory
- Investigar tecnologías de producción
- Colonizar planetas ricos

AGRESIVO:
- Construir flota masiva
- Investigar tecnologías militares
- Lanzar ataques rentables
- Reciclar escombros

DEFENSIVO:
- Fortificar todos los planetas
- Construir defensas pesadas
- Tecnologías de escudo/armadura
- IPMs para ofensa/defensa

EQUILIBRADO:
- Continuar crecimiento económico
- Flota moderada para defensa/ataque
- Colonizar posiciones estratégicas
- Investigar tecnologías variadas
```

### Late Game (1M+ puntos)

**Meta:** Dominio según personalidad

```
ECONÓMICO:
- Minas nivel 30+
- Apoyar aliados con recursos
- Flota mínima defensiva

AGRESIVO:
- Flota masiva (>5M puntos)
- Ataques constantes
- Cazador deRankings

DEFENSIVO:
- Fortalezas impenetrables
- IPMs estratégicos
- Apoyo defensivo a aliados

EQUILIBRADO:
- Equilibrio entre todo
- Flota significativa
- Economía fuerte
- Alianzas activas
```

---

## Implementación

### Nuevas Clases/Servicios

#### 1. BotObjectiveService
```php
class BotObjectiveService
{
    // Determina el objetivo actual del bot
    public function getCurrentObjective(Bot $bot): BotObjective

    // Calcula la puntuación de una opción
    public function scoreOption(array $option, BotObjective $objective): float

    // Obtiene las opciones disponibles
    public function getAvailableOptions(BotService $bot): array
}
```

#### 2. BotObjective (Enum)
```php
enum BotObjective: string
{
    case ECONOMIC_GROWTH = 'economic_growth';
    case FLEET_ACCUMULATION = 'fleet_accumulation';
    case DEFENSIVE_FORTIFICATION = 'defensive_fortification';
    case TERRITORIAL_EXPANSION = 'territorial_expansion';
    case RAIDING_AND_PROFIT = 'raiding_and_profit';
}
```

#### 3. GameStateAnalyzer
```php
class GameStateAnalyzer
{
    // Analiza la situación actual
    public function analyzeCurrentState(BotService $bot): GameState

    // Determina la fase del juego
    public function determineGamePhase(int $points): GamePhase

    // Evalúa amenazas y oportunidades
    public function scanEnvironment(BotService $bot): EnvironmentScan
}
```

### Modificaciones a BotService

```php
class BotService
{
    private BotObjectiveService $objectiveService;
    private GameStateAnalyzer $stateAnalyzer;

    public function makeStrategicDecision(): BotActionType
    {
        // 1. Analizar situación actual
        $state = $this->stateAnalyzer->analyzeCurrentState($this);

        // 2. Determinar objetivo
        $objective = $this->objectiveService->getCurrentObjective(
            $this->bot,
            $state
        );

        // 3. Obtener opciones disponibles
        $options = $this->objectiveService->getAvailableOptions($this);

        // 4. Puntuar y elegir mejor opción
        return $this->chooseBestOption($options, $objective, $state);
    }

    private function chooseBestOption(
        array $options,
        BotObjective $objective,
        GameState $state
    ): BotActionType {
        // Implementar algoritmo de decisión
    }
}
```

---

## Ejemplo de Decisión Estratégica

### Situación:
- Bot: Aggressive, 250k puntos (Mid Game)
- Recursos: 500k metal, 300k crystal, 100k deuterio
- Minas: 22/20/18
- Flota: 150k puntos
- Defensas: 20k puntos

### Análisis:

1. **Fase**: Mid Game
2. **Objetivo**: Fleet Accumulation
3. **Opciones disponibles**:
   - Opción A: Subir metal mine 22→23 (+2.400/hora, costo: 150k/80k/0)
   - Opción B: Subir crystal mine 20→21 (+1.000/hora, costo: 120k/60k/0)
   - Opción C: 200 Cazadores ligeros (+60k puntos flota, costo: 400k/200k/0)
   - Opción D: 50 Cruceros (+100k puntos flota, costo: 450k/250k/50k)
   - Opción E: Investigar weapons tech 8→9

### Puntuación:

| Opción | Impacto Objetivo | ROI | Urgencia | Sinergia | Total |
|--------|------------------|-----|----------|----------|-------|
| A (Mina) | 30 | 60 | 20 | 10 | 70 |
| B (Mina) | 25 | 55 | 15 | 10 | 55 |
| C (CL) | 80 | 70 | 40 | 30 | **220** |
| D (Crucero) | 90 | 65 | 35 | 35 | **225** |
| E (Tech) | 70 | 50 | 25 | 40 | 185 |

### Decisión: **Opción D** - 50 Cruceros

**Razonamiento:**
- Mayor impacto en el objetivo de acumular flota
- Buen ROI (puntos de flota por recursos)
- Alta sinergia (mejora poder de ataque futuro)

---

## Configuración Avanzada

### activity_schedule por Fase
```json
{
  "early_game": {
    "active_hours": [0, 1, 2, ..., 23],
    "focus": ["building", "research"],
    "min_resources_before_action": 5000
  },
  "mid_game": {
    "active_hours": [8, 9, ..., 23],
    "focus": ["building", "fleet", "attack"],
    "min_resources_before_action": 50000
  },
  "late_game": {
    "active_hours": [10, 11, ..., 2],
    "focus": ["fleet", "attack"],
    "min_resources_before_action": 200000
  }
}
```

### Modificadores de Personalidad Estratégicos
```json
{
  "aggressive": {
    "early_game": {"build": 60, "research": 30, "fleet": 10},
    "mid_game": {"build": 20, "research": 15, "fleet": 40, "attack": 25},
    "late_game": {"build": 10, "research": 10, "fleet": 40, "attack": 40}
  },
  "economic": {
    "early_game": {"build": 70, "research": 30},
    "mid_game": {"build": 60, "research": 25, "fleet": 10, "attack": 5},
    "late_game": {"build": 50, "research": 30, "fleet": 15, "attack": 5}
  }
}
```

---

## Métricas y Monitoreo

### KPIs del Sistema Estratégico

```php
class BotStrategicMetrics
{
    // Tasa de crecimiento (puntos por día)
    public function getGrowthRate(Bot $bot): float

    // Eficiencia de recursos (puntos ganados / recursos gastados)
    public function getResourceEfficiency(Bot $bot): float

    // Éxito de ataques (ganancias / pérdidas)
    public function getAttackSuccessRate(Bot $bot): float

    // Progreso hacia objetivo
    public function getObjectiveProgress(Bot $bot): float
}
```

---

Esta guía servirá como base para implementar el sistema estratégico mejorado. La implementación seguirá esta arquitectura para crear bots que toman decisiones inteligentes y estratégicas en lugar de actuar aleatoriamente.
