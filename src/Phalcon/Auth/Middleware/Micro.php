<?php

namespace Dmkit\Phalcon\Auth\Middleware;

use Phalcon\Di\Injectable;
use Phalcon\Mvc\Micro as MvcMicro;
use Phalcon\Events\Event;
use Phalcon\Events\Manager as EventsManager;
use Dmkit\Phalcon\Auth\Auth;
use Dmkit\Phalcon\Auth\TokenGetter\TokenGetter;
use Dmkit\Phalcon\Auth\TokenGetter\Handler\Header;
use Dmkit\Phalcon\Auth\TokenGetter\Handler\QueryStr;

/**
 * Dmkit\Phalcon\Auth\Middleware\Micro.
 * The concept of controllers doesn't exist in Micro apps
 * so checking of URLS and methods have to be done on the Request level.
 */
class Micro extends Injectable
{
    // config key
    public static $configDi = 'config';

    // config section key
    public static $configSection = 'jwtAuth';

    // DI name
    public static $diName = 'auth';

    // JWT payload
    protected $payload;

    // ignored urls
    protected $ignoreUri;

    // ignore IP
    protected $secretKey;

    // JWT secret key
    protected $ignoreOptionsMethod = false;

    // Ignore OPTIONS for CORS support
    protected $auth;

    // Auth Object
    protected $_onUnauthorized;

    // Unauthorize calllback
    private $ignoreIp;

    /**
     * Sets app and config.
     *
     * @param Phalcon\Mvc\Micro  $app
     * @param array              $config
     *
     */
    public function __construct(MvcMicro $app, array $config = null, Auth $auth = null)
    {
        /**
         * example of config:
         * [jwtAuth]
         * secretKey = nSrL7k4/7NcW|AN
         * payload[exp] = 120
         * payload[iss] = phalcon-jwt-auth
         * payload[sub] = 123
         * payload[name] = John Doe
         * payload[role] = admin
         * ignoreUri[] = regex:/register/:POST
         * ignoreUri[] = /register
         */

        if (empty($config)) {
            $appConfig = $this->di->getShared(self::$configDi);
            if (!$appConfig || !isset($appConfig->{self::$configSection})) {
                throw new \InvalidArgumentException('missing DI config jwtAuth and config param');
            }

            $this->config = $appConfig->{self::$configSection}->toArray();
        } else {
            $this->config = $config;
        }

        if (isset($this->config['ignoreUri'])) {
            $this->ignoreUri = $this->config['ignoreUri'];
        }

        if (isset($this->config['ignoreIP'])) {
            $this->ignoreIp = $this->config['ignoreIP'];
        }

        // secret key is required
        if (!isset($this->config['secretKey'])) {
            throw new \InvalidArgumentException('missing jwt secret key');
        }

        $this->secretKey = $this->config['secretKey'];
        $this->payload   = (array)$this->config['payload'] ?? [];

        $this->app  = $app;
        $this->auth = $auth ?? new Auth();

        $this->setEventChecker($app);
        $this->di->setShared('auth', $this);
    }


    /**
     * Sets event authentication.
     *
     */
    protected function setEventChecker($app)
    {
        $eventsManager = $this->di->getEventsManager() ?? new EventsManager();
        $eventsManager->attach(
            "micro:beforeExecuteRoute",
            function (Event $event, $app) {
                /** @var Micro $auth */
                $auth = $app->di->getShared('auth');
                // check if it has CORS support
                if ($auth->isIgnoreOptionsMethod() && $app->request->getMethod() == 'OPTIONS') {
                    return true;
                }

                if ($auth->isIgnoreIp()) {
                    if (!$auth->check() && $this->getMessages()[0] != 'missing token') {
                        return $auth->unauthorized();
                    }
                    return true;
                }

                if ($auth->isIgnoreUri()) {
                    /**
                     * Let's try to parse if there's a token
                     * but we don't want to get an invalid token
                     */
                    if (!$auth->check() && $this->getMessages()[0] != 'missing token') {
                        return $auth->unauthorized();
                    }

                    return true;
                }

                if ($auth->check()) {
                    return true;
                }

                return $auth->unauthorized();
            }
        );
        $app->setEventsManager($eventsManager);
    }

    /**
     * Checks if the request from IP can bypass the authentication.
     *
     * @return bool
     */
    public function isIgnoreIp()
    {
        if (!$this->ignoreIp) {
            return false;
        }
        // access request object
        $request = $this->app->request;

        // ip
        $ip = $request->getClientAddress();

        return in_array($ip, (array)$this->ignoreIp, true);
    }

    /**
     * Checks if the URI and HTTP METHOD can bypass the authentication.
     *
     * @return bool
     */
    public function isIgnoreUri()
    {
        if (!$this->ignoreUri) {
            return false;
        }

        // access request object
        $request = $this->app->request;

        // url
        $uri = $request->getURI();

        // http method
        $method = $request->getMethod();

        return $this->hasMatchIgnoreUri($uri, $method);
    }

    /**
     * Checks the uri and method if it has a match in the passed self::$ignoreUris.
     *
     * @param string $requestUri
     * @param string $requestMethod HTTP METHODS
     *
     * @return bool
     */
    protected function hasMatchIgnoreUri($requestUri, $requestMethod)
    {
        foreach ($this->ignoreUri as $uri) {
            if (strpos($uri, 'regex:') === false) {
                $type = 'str';
            } else {
                $type = 'regex';
                $uri  = str_replace('regex:', '', $uri);
            }

            [$pattern, $methods] = (strpos($uri, ':') === false ? [$uri, false] : explode(':', $uri));
            $methods = (!$methods || empty($methods) ? false : explode(',', $methods));

            $match = ($type == 'str' ? $requestUri == $pattern : preg_match("#{$pattern}#", $requestUri));
            if ($match && (!$methods || in_array($requestMethod, $methods))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Authenticates.
     *
     * @return bool
     */
    public function check()
    {
        $request = $this->app->request;
        $getter  = new TokenGetter(new Header($request), new  QueryStr($request));
        return $this->auth->check($getter, $this->secretKey);
    }

    /**
     * Authenticates.
     *
     * @return bool
     */
    public function make($data)
    {
        $payload = array_merge($this->payload, $data);
        return $this->auth->make($payload, $this->secretKey);
    }

    /**
     * Adds a callback to the Check call
     *
     * @param callable $callback
     */
    public function onCheck($callback)
    {
        $this->auth->onCheck($callback);
    }

    /**
     * Sets the unauthorized return
     *
     * @param callable $callback
     */
    public function onUnauthorized(callable $callback)
    {
        $this->_onUnauthorized = $callback;
    }

    /**
     * Calls the unauthorized function / callback
     *
     * @return bool return false to cancel the router
     */
    public function unauthorized()
    {
        if ($this->_onUnauthorized) {
            return call_user_func($this->_onUnauthorized, $this, $this->app);
        }
        $response = $this->app->response;
        $response->setStatusCode(401, 'Unauthorized');
        $response->setContentType("application/json");
        $response->setContent(json_encode([$this->getMessages()[0]]));

        // CORS
        if ($this->isIgnoreOptionsMethod()) {
            $response->setHeader("Access-Control-Allow-Origin", '*')
                ->setHeader("Access-Control-Allow-Methods", 'GET,PUT,POST,DELETE,OPTIONS')
                ->setHeader(
                    "Access-Control-Allow-Headers",
                    'Origin, X-Requested-With, Content-Range, Content-Disposition, Content-Type, Authorization'
                )
                ->setHeader("Access-Control-Allow-Credentials", true);
        }

        $response->send();
        return false;
    }

    /**
     * Returns error messages
     *
     * @return array
     */
    public function getMessages()
    {
        return $this->auth->getMessages();
    }

    /**
     *  Checks if OPTIONS METHOD Should be ignored
     *
     */
    public function isIgnoreOptionsMethod()
    {
        return $this->ignoreOptionsMethod;
    }

    /**
     *  Ignore OPTIONS for CORS support
     *
     */
    public function setIgnoreOptionsMethod()
    {
        $this->ignoreOptionsMethod = true;
    }

    /**
     * Returns JWT payload sub or payload id.
     *
     * @return string
     */
    public function id()
    {
        return $this->auth->id();
    }

    /**
     * Returns payload or value of payload key.
     *
     * @param array  $payload
     * @param string $key
     *
     * @return array|string
     */
    public function data($field = null)
    {
        return $this->auth->data($field);
    }
}
