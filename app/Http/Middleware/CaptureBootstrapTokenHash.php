<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;

final class CaptureBootstrapTokenHash
{
    /** @var list<string> */
    private const BODY_FIELDS = [
        'bootstrap_token',
        'name',
        'email',
        'password',
        'password_confirmation',
    ];

    public const HASH_ATTRIBUTE = 'bootstrap_token_hash';

    public const VALID_ATTRIBUTE = 'bootstrap_token_valid';

    private const TOKEN_PATTERN = '/\Anxb_[a-f0-9]{64}\z/D';

    private const TOKEN_FRAGMENT_PATTERN = '/nxb_[a-f0-9]{64}/';

    private const INVALID_TOKEN_HASH = '258af7cc12f6eb7c531c1347f6b6690aeb9c710a66a4d99c2fa9f18c238c45da';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $requestParameters = $request->isJson()
            ? $request->json()->all()
            : $request->request->all();
        $queryParameters = $request->query->all();
        $token = $requestParameters['bootstrap_token'] ?? null;
        $unknownBodyFieldsPresent = array_diff(
            array_keys($requestParameters),
            self::BODY_FIELDS,
        ) !== [];
        $queryInputPresent = $queryParameters !== [];
        $headerTokenPresent = $request->headers->has('X-Nexus-Bootstrap-Token')
            || $this->headersContainBootstrapToken($request);
        $valid = is_string($token)
            && strlen($token) === 68
            && preg_match(self::TOKEN_PATTERN, $token) === 1
            && ! $unknownBodyFieldsPresent
            && ! $queryInputPresent
            && ! $headerTokenPresent;
        $tokenHash = $valid ? hash('sha256', $token) : self::INVALID_TOKEN_HASH;

        unset($requestParameters['bootstrap_token']);
        unset($token);
        $requestParameters = array_intersect_key(
            $requestParameters,
            array_flip(array_diff(self::BODY_FIELDS, ['bootstrap_token'])),
        );
        $queryParameters = [];

        $attributes = $request->attributes->all();
        $cookies = $request->cookies->all();
        $files = $request->files->all();
        $server = $request->server->all();
        $queryString = http_build_query($queryParameters, '', '&', PHP_QUERY_RFC3986);
        $requestPath = parse_url($request->getRequestUri(), PHP_URL_PATH);
        $server['REQUEST_METHOD'] = $request->getMethod();
        $server['REQUEST_URI'] = (is_string($requestPath) && $requestPath !== ''
            ? $requestPath
            : $request->getPathInfo()).($queryString === '' ? '' : '?'.$queryString);
        $server['QUERY_STRING'] = $queryString;
        $server['CONTENT_LENGTH'] = '0';
        unset($server['HTTP_CONTENT_LENGTH'], $server['HTTP_X_NEXUS_BOOTSTRAP_TOKEN']);

        foreach ($server as $key => $value) {
            if (is_string($value)
                && preg_match(self::TOKEN_FRAGMENT_PATTERN, $value) === 1) {
                unset($server[$key]);
            }
        }

        $routeResolver = $request->getRouteResolver();
        $userResolver = $request->getUserResolver();

        $request->initialize(
            $queryParameters,
            $requestParameters,
            $attributes,
            $cookies,
            $files,
            $server,
            '',
        );
        $request->setRouteResolver($routeResolver);
        $request->setUserResolver($userResolver);

        if ($request->isJson()) {
            $request->setJson(new InputBag($requestParameters));
        }

        $request->attributes->set(self::HASH_ATTRIBUTE, $tokenHash);
        $request->attributes->set(self::VALID_ATTRIBUTE, $valid);

        return $next($request);
    }

    private function headersContainBootstrapToken(Request $request): bool
    {
        foreach ($request->headers->all() as $values) {
            foreach ($values as $value) {
                if (preg_match(self::TOKEN_FRAGMENT_PATTERN, $value) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
