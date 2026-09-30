<?php

use App\Exceptions\ConflictException;
use App\Http\Middleware\ResolveTenantContext;
use App\Services\TenantOwnershipMismatchException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => ResolveTenantContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // O fetch do navegador não manda Accept: application/json; sem isto
        // erros como 403/404 voltariam como página HTML e a extensão não
        // conseguiria mostrar o motivo.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson(),
        );

        // Respostas de erro consistentes para toda a API (T009): nunca
        // vazar detalhes de existência de recursos em 401/403 (FR-015).
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Não autenticado.'], 401);
            }
        });

        // O Laravel converte AuthorizationException em AccessDeniedHttpException
        // antes dos callbacks de render — por isso é esta a classe que casa.
        // Mensagem fixa: não revela por que o acesso foi negado (FR-015).
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Você não tem permissão para esta ação. Se acha que deveria ter, peça a um administrador para liberar seu acesso.',
                ], 403);
            }
        });

        $exceptions->render(function (ConflictException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 409);
            }
        });

        $exceptions->render(function (TenantOwnershipMismatchException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 409);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Dados inválidos.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });
    })->create();
