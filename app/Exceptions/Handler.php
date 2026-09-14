<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2025. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Exceptions;

use Throwable;
use PDOException;
use App\Utils\Ninja;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Database\Eloquent\RelationNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Illuminate\Database\Eloquent\ModelNotFoundException as ModelNotFoundException;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        // PDOException::class,
        MaxAttemptsExceededException::class,
        CommandNotFoundException::class,
        ValidationException::class,
        // ModelNotFoundException::class,
        NotFoundHttpException::class,
        RelationNotFoundException::class,
        NoNodeAvailableException::class,
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<1, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     *
     * @param Throwable $exception
     * @return void
     * @throws Throwable
     */
    public function report(Throwable $exception)
    {
        parent::report($exception);

        if (Ninja::isSelfHost() && $exception instanceof MissingAppKeyException) {
            info('To setup the app run: cp .env.example .env');
        }
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param Request $request
     * @param Throwable $exception
     * @throws Throwable
     */
    public function render($request, Throwable $exception)
    {
        if ($exception instanceof ModelNotFoundException && $request->expectsJson()) {
            return response()->json(['message' => $exception->getMessage()], 400);
        } elseif ($exception instanceof InternalPDFFailure && $request->expectsJson()) {
            return response()->json(['message' => $exception->getMessage()], 500);
        } elseif ($exception instanceof PhantomPDFFailure && $request->expectsJson()) {
            return response()->json(['message' => $exception->getMessage()], 500);
        } elseif ($exception instanceof FilePermissionsFailure) {
            return response()->json(['message' => $exception->getMessage()], 500);
        } elseif ($exception instanceof ThrottleRequestsException && $request->expectsJson()) {
            return response()->json(['message' => 'Too many requests'], 429);
            // } elseif ($exception instanceof FatalThrowableError && $request->expectsJson()) {
            //     return response()->json(['message'=>'Fatal error'], 500); //@deprecated
        } elseif ($exception instanceof AuthorizationException && $request->expectsJson()) {
            return response()->json(['message' => $exception->getMessage()], 401);
        } elseif ($exception instanceof TokenMismatchException) {
            return redirect()
                    ->back()
                    ->withInput($request->except('password', 'password_confirmation', '_token'))
                    ->with([
                        'message' => ctrans('texts.token_expired'),
                        'message-type' => 'danger', ]);
        } elseif ($exception instanceof NotFoundHttpException && $request->expectsJson()) {
            return response()->json(['message' => 'Route does not exist'], 404);
        } elseif ($exception instanceof MethodNotAllowedHttpException && $request->expectsJson()) {
            return response()->json(['message' => 'Method not supported for this route'], 404);
        } elseif ($exception instanceof ValidationException && $request->expectsJson()) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => $exception->validator->getMessageBag()], 422);
        } elseif ($exception instanceof RelationNotFoundException && $request->expectsJson()) {
            return response()->json(['message' => "Relation `{$exception->relation}` is not a valid include."], 400);
        } elseif ($exception instanceof GenericPaymentDriverFailure && $request->expectsJson()) {
            return response()->json(['message' => $exception->getMessage()], 400);
        } elseif ($exception instanceof GenericPaymentDriverFailure) {
            return response()->json(['message' => $exception->getMessage()], 400);
        } elseif ($exception instanceof StripeConnectFailure) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }

        return parent::render($request, $exception);
    }

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $guard = Arr::get($exception->guards(), 0);

        switch ($guard) {
            case 'contact':
                $login = 'client.login';
                break;
            case 'user':
                $login = 'login';
                break;
            case 'vendor':
                $login = 'vendor.catchall';
                break;
            case 'ronin':
                $login = 'ronin.login';
                break;
            default:
                $login = 'default';
                break;
        }

        return redirect()->guest(route($login));
    }
}
