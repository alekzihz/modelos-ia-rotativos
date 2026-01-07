# modelos-ia-rotativos

API en **Slim 4** (PHP) para consumir **modelos de IA** en cada petición y **rotar proveedores** (por ejemplo **Groq**, **Cerebras**, y en el futuro OpenAI/Claude). Incluye streaming (respuesta progresiva) usando `curl` y un sistema de rotación **round-robin**.

---

## ¿Qué hace este proyecto?

- Expone endpoints HTTP con Slim.
- En cada petición el backend elige el **siguiente servicio IA** (round-robin) de una lista.
- Envía la petición al proveedor (Groq/Cerebras) y va escribiendo la respuesta **en streaming** (chunks) en tiempo real.
- Permite añadir nuevos proveedores implementando una interfaz común (`IAService`).

---

## Tecnologías

- **PHP 8.3+** (recomendado)
- **Slim Framework 4**
- **slim/psr7** (implementación PSR-7)
- **vlucas/phpdotenv** (variables de entorno desde `.env`)
- **cURL** (streaming hacia APIs compatibles)

---

## Requisitos

- PHP con extensiones:

  - `curl`
  - (opcional) `mbstring`

- Composer

---

## Instalación

1. Clona el repositorio y entra en la carpeta:

```bash
git clone <tu-repo>
cd modelos-ia-rotativos
```

2. Instala dependencias:

```bash
composer install
```

3. Crea el archivo `.env` en la raíz del proyecto:

```bash
cp .env.example .env
```

Ejemplo de `.env` (ajusta tus claves):

```
# Groq
GROQ_API_KEY=tu_key
GROQ_MODEL=moonshotai/kimi-k2-instruct-0905

# Cerebras
CEREBRAS_API_KEY=tu_key
CEREBRAS_MODEL=gpt-oss-120b
```

4. Regenera autoload (si añadiste clases nuevas):

```bash
composer dump-autoload
```

---

## Ejecutar en local

Desde la raíz del proyecto:

```bash
php -S localhost:3004 -t public public/index.php
```

Luego abre:

- `http://localhost:3004/demo`

---

## Endpoints

### `GET /demo`

Ejecuta una petición de ejemplo y devuelve texto en streaming.

- Selecciona el servicio IA mediante **round-robin**.
- Envía un prompt simple.
- Va imprimiendo los deltas hasta finalizar.

---

## Cómo funciona la rotación (round-robin)

El proyecto mantiene un índice en un fichero (por defecto):

- `/tmp/ai_rr_index.txt`

Cada request:

- lee el índice
- devuelve el servicio `services[index]`
- incrementa y hace `mod services.length`

Esto permite rotación incluso en entornos donde cada petición PHP se ejecuta en procesos separados.

---

## Arquitectura del código

Estructura típica:

```
/public
  index.php
/src
  /Modelos
    Role.php
    ChatMessage.php
    IAService.php
    IAServiceRotator.php
  /Services
    GroqService.php
    CerebrasService.php
/vendor
.env
composer.json
```

### Modelos (DTOs)

- `Role` (enum): `user`, `assistant`, `system`
- `ChatMessage`: `{ role, content }`

### Interfaz de proveedor IA

`IAService` define el contrato común:

- `name(): string`
- `stream(array $messages, callable $onDelta): void`

### Servicios

- `GroqService implements IAService`
- `CerebrasService implements IAService`

Ambos:

- leen `*_API_KEY` desde `$_ENV`
- usan `curl` en modo streaming
- parsean líneas `data: ...` estilo SSE
- llaman a `$onDelta($chunk)` por cada delta

---

## Añadir un nuevo proveedor

1. Crea `src/Services/NuevoProveedorService.php` e implementa `IAService`.
2. Añade `NUEVOPROVEEDOR_API_KEY` y (opcional) `NUEVOPROVEEDOR_MODEL` a `.env`.
3. Registra el servicio en el rotator (en `public/index.php` o en un bootstrap).

Ejemplo:

```php
$rotator = new IAServiceRotator([
  new GroqService(),
  new CerebrasService(),
  new OpenAIService(),
]);
```

---

## Notas importantes

- `require __DIR__ . '/../vendor/autoload.php';` debe ir **antes** de instanciar clases.
- Tras añadir clases nuevas, ejecuta `composer dump-autoload`.
- Con la interfaz actual, el modelo se define **dentro** del servicio (por `.env`).

---
