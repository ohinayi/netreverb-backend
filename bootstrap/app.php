<?php

use App\Http\Middleware\ApiSecurityHeaders;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        channels: __DIR__.'/../routes/channels.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->appendToGroup('api', ApiSecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/v1/organizations/*/conference-rooms/*')) {
                return null;
            }

            $previous = $exception->getPrevious();

            if (! $previous instanceof ModelNotFoundException) {
                return null;
            }

            return match ($previous->getModel()) {
                ConferenceRoom::class => response()->json([
                    'message' => 'Conference room not found for this organization.',
                ], 404),
                ConferenceRoomParticipant::class => response()->json([
                    'message' => 'Conference room participant not found for this room.',
                ], 404),
                default => null,
            };
        });
    })->create();
