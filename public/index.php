<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use App\Services\GroqService;
use App\Services\CerebrasService;
use App\Services\OpenAIService;

use App\Modelos\ChatMessage;
use App\Modelos\Role;
use App\Modelos\IAServiceRotator;
use App\Excepciones\AIServiceException;

require __DIR__ . '/../vendor/autoload.php';

#carga de variables de entorno
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$rotator = new IAServiceRotator([
    new OpenAIService(),
    new GroqService(),
    new CerebrasService(),
    // otros servicios IA aquí...
]);


$app = AppFactory::create();

$app->get('/', function (Request $request, Response $response, $args) {
    $response->getBody()->write("Hello world!");
    return $response;
});

# Endpoint de ejemplo que usa GroqService en streaming SSE
$app->get('/chat', function ($request, $response) {
    $groq = new GroqService();

    // Headers SSE
    $response = $response
        ->withHeader('Content-Type', 'text/event-stream')
        ->withHeader('Cache-Control', 'no-cache')
        ->withHeader('Connection', 'keep-alive');

    // OJO: Slim/PSR-7 no está pensado para echo directo, pero funciona si no hay buffer raro.
    echo "event: start\n";
    echo "data: ok\n\n";
    @ob_flush();
    @flush();

    $groq->createStream(
        [['role' => 'user', 'content' => 'Dame 5 ideas para una API en Slim con IA.']],
        'moonshotai/kimi-k2-instruct-0905',
        function (string $delta) {
            // Cada delta como evento SSE
            $safe = str_replace(["\n", "\r"], ['\\n', ''], $delta);
            echo "data: {$safe}\n\n";
            @ob_flush();
            @flush();
        }
    );

    echo "event: end\n";
    echo "data: [DONE]\n\n";
    @ob_flush();
    @flush();

    return $response;
});

# end

$app->get('/demo', function ($request, $response) use ($rotator) {
    //$groq = new GroqService();
    $peticion = $request->getQueryParams();

    if (empty($peticion)) {
        $peticion = $request->getParsedBody();

        // Si por lo que sea no parsea, hacemos fallback leyendo crudo
        if (!is_array($peticion)) {
            $raw = (string) $request->getBody();
            $peticion = json_decode($raw, true) ?: [];
        }
    }


    $requestMessage = $peticion['message'] ?? 'Dame 5 ideas para una API en Slim con IA.';
    $serviceIa = $rotator->next();
    $messages = [
        new ChatMessage(Role::User, $requestMessage)
    ];


    // Texto normal (no SSE)
    $response = $response
        ->withHeader('Content-Type', 'text/plain; charset=utf-8')
        ->withHeader('Cache-Control', 'no-cache')
        ->withHeader('X-Accel-Buffering', 'no'); // ayuda si algún día hay nginx


    // Quitar buffers para que flush haga efecto
    ini_set('output_buffering', '0');
    ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    ob_implicit_flush(true);
    try {

        echo "Generando...\n\n";
        echo "Usando servicio: " . $serviceIa->name() . "\n\n";
        @flush();

        $serviceIa->stream(
            $messages,
            function (string $delta) {
                echo $delta;
                @flush();
            }


        );

        echo "\n\n[DONE]\n";
        @flush();
    } catch (Throwable $e) {
        if ($e instanceof AIServiceException) {
            echo "\n\n[ERROR en {$e->provider}] ";
            echo "Tipo: " . erroresGeneralesServicios($e);
        } else {
            echo "\n\n[ERROR] " . $e->getMessage() . "\n";
        }
        @flush();
    }


    return $response;
});


$app->run();
function erroresGeneralesServicios(AIServiceException $serviceExcepcion): string
{
    if ($serviceExcepcion->isQuota()) return 'Limite de cuota Alcanzada';
    if ($serviceExcepcion->isRateLimit()) return 'Limite de peticiones alcanzado';
    if ($serviceExcepcion->isAuth()) return 'Error de autenticación';
    if ($serviceExcepcion->isModelNotFound()) return 'Modelo no disponible';

    return 'unknown';
}
