<?php

/**
 * Router.php
 * Application Router - Singleton Pattern
 * @package EduTrack
 * @subpackage Routes
 * @version 2.0
 */

class Router
{
    private static $instance = null;
    private $routes = [];

    private function __construct() {}
    private function __clone() {}

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get($path, $callback)
    {
        $this->routes['GET'][$path] = $callback;
    }

    public function post($path, $callback)
    {
        $this->routes['POST'][$path] = $callback;
    }

    public function put($path, $callback)
    {
        $this->routes['PUT'][$path] = $callback;
    }

    public function delete($path, $callback)
    {
        $this->routes['DELETE'][$path] = $callback;
    }

    public function dispatch($method, $path)
    {
        $method = strtoupper($method);

        // Remove query string if present
        if (strpos($path, '?') !== false) {
            $path = strstr($path, '?', true);
        }

        // Check if we have routes for this method
        if (!isset($this->routes[$method])) {
            $this->send404();
            return;
        }

        // Try to match exact route
        if (isset($this->routes[$method][$path])) {
            $callback = $this->routes[$method][$path];
            if (is_callable($callback)) {
                $callback([]);
                return;
            }
            $this->send404();
            return;
        }

        // Try to match route with parameters
        foreach ($this->routes[$method] as $route => $callback) {
            // Convert route to regex pattern
            $pattern = preg_replace('/\{[a-zA-Z0-9_]+\}/', '([a-zA-Z0-9_-]+)', $route);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $path, $matches)) {
                array_shift($matches);

                // Extract parameter names
                preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $route, $paramNames);
                $paramNames = $paramNames[1];

                // Build request params
                $request = [];
                foreach ($paramNames as $index => $name) {
                    $request[$name] = $matches[$index] ?? null;
                }

                if (is_callable($callback)) {
                    $callback($request);
                    return;
                }
                $this->send404();
                return;
            }
        }

        $this->send404();
    }

    private function send404()
    {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Endpoint not found',
            'code' => 'ROUTE_NOT_FOUND',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}
