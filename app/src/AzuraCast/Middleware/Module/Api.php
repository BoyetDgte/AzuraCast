<?php
namespace AzuraCast\Middleware\Module;

use Entity;
use App\Session;
use Slim\Http\Request;
use Slim\Http\Response;

/**
 * Handle API calls and wrap exceptions in JSON formatting.
 */
class Api
{
    /** @var Entity\Repository\ApiKeyRepository */
    protected $api_repo;

    /** @var Session */
    protected $session;

    public function __construct(Session $session, Entity\Repository\ApiKeyRepository $api_repo)
    {
        $this->session = $session;
        $this->api_repo = $api_repo;
    }

    /**
     * @param Request $request
     * @param Response $response
     * @param callable $next
     * @return Response
     */
    public function __invoke(Request $request, Response $response, $next): Response
    {
        // Prevent unnecessary session creation on API pages from flooding the session databases
        if (!$this->session->exists()) {
            $this->session->disable();
        }

        // Attempt API key auth if a key exists.
        $api_key_header = $request->getHeader('X-API-Key');
        $api_key = $api_key_header[0] ?? $request->getParam('key') ?? null;

        $api_user = (!empty($api_key)) ? $this->api_repo->authenticate($api_key) : null;

        // Override the request's "user" variable if API authentication is supplied and valid.
        if ($api_user instanceof Entity\User) {
            $request = $request->withAttribute('user', $api_user);
        }

        // Only set global CORS for GET requests and API-authenticated requests;
        // Session-authenticated, non-GET requests should only be made in a same-host situation.
        if ($api_user instanceof Entity\User || $request->isGet()) {
            $response = $response->withHeader('Access-Control-Allow-Origin', '*');
        }

        // Set default cache control for API pages.
        $response = $response->withHeader('Cache-Control', 'public, max-age=' . 30)
            ->withHeader('X-Accel-Expires', 30); // CloudFlare caching

        // Custom error handling for API responses.
        try {
            return $next($request, $response);
        } catch(\App\Exception\PermissionDenied $e) {
            return $response->withStatus(403)->withJson([
                'code' => 403,
                'message' => 'Permission denied',
            ]);
        } catch (\App\Exception\NotLoggedIn $e) {
            return $response->withStatus(403)->withJson([
                'code' => 403,
                'message' => 'Login required',
            ]);
        } catch(\Exception $e) {
            $return_data = [
                'type' => get_class($e),
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ];

            if (!APP_IN_PRODUCTION) {
                $return_data['stack_trace'] = $e->getTrace();
            }

            return $response->withStatus(500)->withJson($return_data);
        }
    }
}