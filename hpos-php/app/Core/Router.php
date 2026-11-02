<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<array{string,string,callable,array}> */
    private array $routes = [];

    /**
     * @param array{auth?:bool,perm?:array{string,string}} $opts
     *   auth: false = public route (default true)
     *   perm: [resource, action] checked before the controller runs (the DB checks again)
     */
    public function add(string $method, string $pattern, callable $handler, array $opts = []): void
    {
        $this->routes[] = [$method, $pattern, $handler, $opts];
    }

    public function get(string $pattern, callable $h, array $o = []): void  { $this->add('GET', $pattern, $h, $o); }
    public function post(string $pattern, callable $h, array $o = []): void { $this->add('POST', $pattern, $h, $o); }

    /**
     * Every route, for tooling (tools/audit_routes.php).
     *
     * @return list<array{method:string,pattern:string,auth:bool,perm:?array}>
     */
    public function routes(): array
    {
        return array_map(static fn (array $r): array => [
            'method' => $r[0], 'pattern' => $r[1], 'auth' => ($r[3]['auth'] ?? true) === true, 'perm' => $r[3]['perm'] ?? null,
        ], $this->routes);
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = $this->path();

        $pathMatched = false;
        foreach ($this->routes as [$m, $pattern, $handler, $opts]) {
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (!preg_match($regex, $path, $match)) {
                continue;
            }
            $pathMatched = true;
            if ($m !== $method) {
                continue;
            }
            $params = array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY);
            $this->run($handler, $params, $opts, $path);
            return;
        }

        if ($pathMatched) {
            http_response_code(405);
            header('Allow: GET, POST');
            $this->errorPage(405, 'Method not allowed');
            return;
        }
        $this->errorPage(404, 'Page not found');
    }

    private function run(callable $handler, array $params, array $opts, string $path): void
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::valid()) {
                Flash::error('Your session expired. Please try again.');
                redirect($path === '/login' ? '/login' : '/');
            }
            if (($opts['auth'] ?? true) === true) {
                Auth::requireLogin();
            }
            if (isset($opts['perm']) && !Auth::can($opts['perm'][0], $opts['perm'][1])) {
                $this->errorPage(403, 'You do not have access to this page');
                return;
            }
            $handler($params);
        } catch (AppError $e) {
            if ($e->isForbidden()) {
                $this->errorPage(403, $e->getMessage());
                return;
            }
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $this->errorPage(400, $e->getMessage());
                return;
            }
            // A form post failed: show the DB's message on the page the user came from.
            Flash::error($e->getMessage());
            redirect($this->cameFrom());
        }
    }

    /** Path of the Referer when it is on this host, else the home page. Only the path is used. */
    private function cameFrom(): string
    {
        $ref = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if (!$ref || ($ref['host'] ?? '') !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
            return '/';
        }
        $path = (string) ($ref['path'] ?? '/');
        $base = base_path();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        return ($path === '' ? '/' : $path) . (isset($ref['query']) ? '?' . $ref['query'] : '');
    }

    private function path(): string
    {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $base = base_path();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $uri = '/' . trim($uri, '/');
        return $uri;
    }

    private function errorPage(int $code, string $message): void
    {
        http_response_code($code);
        $tpl = in_array($code, [403, 404], true) ? "errors/$code" : 'errors/generic';        View::render($tpl, ['title' => $message, 'code' => $code, 'message' => $message], Auth::check() ? 'app' : 'bare');
    }
}
