<?php

if (!function_exists('request_parse_body')) {
    function request_parse_body(): array {
        $post = [];
        $files = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str(file_get_contents('php://input'), $post);
        }
        return [$post, $files];
    }
}

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
        ]);
        $middleware->append(\App\Http\Middleware\CastResponseNumbers::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Database\QueryException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                if ($e->getCode() == 23000 || (int) $e->errorInfo[1] === 1062) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Error de duplicidad: Ya existe un registro con información idéntica en el sistema.'
                    ], 422);
                }
                return response()->json([
                    'success' => false,
                    'message' => 'Hubo un inconveniente al procesar la información en la base de datos.'
                ], 500);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'El recurso o servicio solicitado no se encuentra disponible.'
                ], 404);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sesión expirada o no autorizada. Por favor, inicia sesión nuevamente.'
                ], 401);
            }
        });
    })->create();
