<?php

declare(strict_types=1);

use App\Backends\Common\Cache as BackendCache;
use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Context;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Extends\Date;
use App\Libs\Router;
use App\Libs\Uri;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        if (false === ($value = $_ENV[$key] ?? getenv($key))) {
            return getValue($default);
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'empty', '(empty)' => '',
            'null', '(null)' => null,
            default => $value,
        };
    }
}

if (!function_exists('getValue')) {
    function getValue(mixed $var): mixed
    {
        return ($var instanceof Closure) ? $var() : $var;
    }
}

if (!function_exists('makeDate')) {
    /**
     * Make Date Time Object.
     *
     * @param string|int|DateTimeInterface $date Defaults to now
     * @param string|DateTimeZone|null $tz For given $date, not for display.
     *
     * @return Date
     */
    function makeDate(string|int|DateTimeInterface $date = 'now', DateTimeZone|string|null $tz = null): Date
    {
        if (ctype_digit((string)$date)) {
            $date = '@' . $date;
        }

        if (null === $tz) {
            $tz = date_default_timezone_get();
        }

        if (!($tz instanceof DateTimeZone)) {
            $tz = new DateTimeZone($tz);
        }

        if (true === ($date instanceof DateTimeInterface)) {
            $date = $date->format(DateTimeInterface::ATOM);
        }

        return (new Date($date))->setTimezone($tz);
    }
}

if (!function_exists('ag')) {
    function ag(array|object $array, string|array|int|null $path, mixed $default = null, string $separator = '.'): mixed
    {
        if (empty($path)) {
            return $array;
        }

        if (!is_array($array)) {
            $array = get_object_vars($array);
        }

        if (is_array($path)) {
            foreach ($path as $key) {
                $val = ag($array, $key, '_not_set');
                if ('_not_set' === $val) {
                    continue;
                }
                return $val;
            }
            return getValue($default);
        }

        if (null !== ($array[$path] ?? null)) {
            return $array[$path];
        }

        if (!str_contains($path, $separator)) {
            return $array[$path] ?? getValue($default);
        }

        foreach (explode($separator, $path) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return getValue($default);
            }
        }

        return $array;
    }
}

if (!function_exists('ag_set')) {
    /**
     * Set an array item to a given value using "dot" notation.
     *
     * If no key is given to the method, the entire array will be replaced.
     *
     * @param array $array
     * @param string $path
     * @param mixed $value
     * @param string $separator
     *
     * @return array return modified array.
     */
    function ag_set(array $array, string $path, mixed $value, string $separator = '.'): array
    {
        $keys = explode($separator, $path);

        $at = &$array;

        while (count($keys) > 0) {
            if (1 === count($keys)) {
                if (is_array($at)) {
                    $at[array_shift($keys)] = $value;
                } else {
                    throw new RuntimeException("Can not set value at this path ($path) because its not array.");
                }
            } else {
                $path = array_shift($keys);
                if (!isset($at[$path])) {
                    $at[$path] = [];
                }
                $at = &$at[$path];
            }
        }

        return $array;
    }
}

if (!function_exists('ag_exists')) {
    /**
     * Determine if the given key exists in the provided array.
     *
     * @param array $array
     * @param string|int $path
     * @param string $separator
     *
     * @return bool
     */
    function ag_exists(array $array, string|int $path, string $separator = '.'): bool
    {
        if (isset($array[$path])) {
            return true;
        }

        foreach (explode($separator, (string)$path) as $lookup) {
            if (isset($array[$lookup])) {
                $array = $array[$lookup];
            } else {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('ag_delete')) {
    /**
     * Delete given key path.
     *
     * @param array $array
     * @param int|string $path
     * @param string $separator
     * @return array
     */
    function ag_delete(array $array, string|int $path, string $separator = '.'): array
    {
        if (array_key_exists($path, $array)) {
            unset($array[$path]);

            return $array;
        }

        if (is_int($path)) {
            if (isset($array[$path])) {
                unset($array[$path]);
            }
            return $array;
        }

        $items = &$array;

        $segments = explode($separator, $path);

        $lastSegment = array_pop($segments);

        foreach ($segments as $segment) {
            if (!isset($items[$segment]) || !is_array($items[$segment])) {
                continue;
            }

            $items = &$items[$segment];
        }

        if (null !== $lastSegment && array_key_exists($lastSegment, $items)) {
            unset($items[$lastSegment]);
        }

        return $array;
    }
}

if (!function_exists('fixPath')) {
    function fixPath(string $path): string
    {
        return rtrim(implode(DIRECTORY_SEPARATOR, explode(DIRECTORY_SEPARATOR, $path)), DIRECTORY_SEPARATOR);
    }
}

if (!function_exists('fsize')) {
    function fsize(string|int $bytes = 0, bool $showUnit = true, int $decimals = 2, int $mod = 1000): string
    {
        $sz = 'BKMGTP';

        $factor = floor((strlen((string)$bytes) - 1) / 3);

        return sprintf("%.{$decimals}f", (int)($bytes) / ($mod ** $factor)) . ($showUnit ? $sz[(int)$factor] : '');
    }
}

if (!function_exists('saveWebhookPayload')) {
    function saveWebhookPayload(iFace $entity, ServerRequestInterface $request): void
    {
        $content = [
            'request' => [
                'server' => $request->getServerParams(),
                'body' => (string)$request->getBody(),
                'query' => $request->getQueryParams(),
            ],
            'parsed' => $request->getParsedBody(),
            'attributes' => $request->getAttributes(),
            'entity' => $entity->getAll(),
        ];

        $filename = r(Config::get('webhook.file_format', 'webhook.{backend}.{event}.{id}.json'), [
            'time' => (string)time(),
            'backend' => $entity->via,
            'event' => ag($entity->getExtra($entity->via), 'event', 'unknown'),
            'id' => ag($request->getServerParams(), 'X_REQUEST_ID', time()),
            'date' => makeDate('now')->format('Ymd'),
            'context' => $content,
        ]);

        @file_put_contents(
            Config::get('tmpDir') . '/webhooks/' . $filename,
            json_encode(
                value: $content,
                flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE
            )
        );
    }
}

if (!function_exists('saveRequestPayload')) {
    function saveRequestPayload(ServerRequestInterface $request): void
    {
        $content = [
            'query' => $request->getQueryParams(),
            'parsed' => $request->getParsedBody(),
            'server' => $request->getServerParams(),
            'body' => (string)$request->getBody(),
            'attributes' => $request->getAttributes(),
        ];

        @file_put_contents(
            Config::get('tmpDir') . '/debug/' . sprintf(
                'request.%s.json',
                ag($request->getServerParams(), 'X_REQUEST_ID', (string)time())
            ),
            json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}

if (!function_exists('jsonResponse')) {
    function jsonResponse(int $status, array $body, $headers = []): ResponseInterface
    {
        $headers['Content-Type'] = 'application/json';

        return new Response(
            status: $status,
            headers: $headers,
            body: json_encode(
                $body,
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
            )
        );
    }
}

if (!function_exists('httpClientChunks')) {
    /**
     * Handle Response Stream as Chunks
     *
     * @param ResponseStreamInterface $stream
     * @return Generator
     *
     * @throws TransportExceptionInterface
     */
    function httpClientChunks(ResponseStreamInterface $stream): Generator
    {
        foreach ($stream as $chunk) {
            yield $chunk->getContent();
        }
    }
}

if (!function_exists('queuePush')) {
    function queuePush(iFace $entity, bool $remove = false): void
    {
        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            return;
        }

        try {
            $cache = Container::get(CacheInterface::class);

            $list = $cache->get('queue', []);

            if (true === $remove && array_key_exists($entity->id, $list)) {
                unset($list[$entity->id]);
            } else {
                $list[$entity->id] = ['id' => $entity->id];
            }

            $cache->set('queue', $list, new DateInterval('P7D'));
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            Container::get(LoggerInterface::class)->error($e->getMessage(), $e->getTrace());
        }
    }
}

if (!function_exists('afterLast')) {
    function afterLast(string $subject, string $search): string
    {
        if (empty($search)) {
            return $subject;
        }

        $position = mb_strrpos($subject, $search, 0);

        if (false === $position) {
            return $subject;
        }

        return mb_substr($subject, $position + mb_strlen($search));
    }
}

if (!function_exists('before')) {
    function before(string $subject, string $search): string
    {
        return $search === '' ? $subject : explode($search, $subject)[0];
    }
}

if (!function_exists('after')) {
    function after(string $subject, string $search): string
    {
        return empty($search) ? $subject : array_reverse(explode($search, $subject, 2))[0];
    }
}

if (!function_exists('makeBackend')) {
    /**
     * Create new Backend Client instance.
     *
     * @param array{name:string|null, type:string, url:string, token:string|int|null, user:string|int|null, options:array} $backend
     * @param string|null $name server name.
     *
     * @return iClient
     *
     * @throws RuntimeException if configuration is wrong.
     */
    function makeBackend(array $backend, string|null $name = null): iClient
    {
        if (null === ($backendType = ag($backend, 'type'))) {
            throw new RuntimeException('No backend type was set.');
        }

        if (null === ag($backend, 'url')) {
            throw new RuntimeException('No Backend url was set.');
        }

        if (null === ($class = Config::get("supported.{$backendType}", null))) {
            throw new RuntimeException(
                r('Unexpected client type [{type}] was given. Expecting [{list}]', [
                    'type' => $backendType,
                    'list' => array_keys(Config::get('supported', [])),
                ])
            );
        }

        return Container::getNew($class)->withContext(
            new Context(
                clientName: $backendType,
                backendName: $name ?? ag($backend, 'name', '??'),
                backendUrl: new Uri(ag($backend, 'url')),
                cache: Container::get(BackendCache::class),
                backendId: ag($backend, 'uuid', null),
                backendToken: ag($backend, 'token', null),
                backendUser: ag($backend, 'user', null),
                options: ag($backend, 'options', []),
            )
        );
    }
}

if (!function_exists('arrayToString')) {
    function arrayToString(array $arr, string $separator = ', '): string
    {
        $list = [];

        foreach ($arr as $key => $val) {
            if (is_object($val)) {
                if (($val instanceof JsonSerializable)) {
                    $val = json_encode($val, flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } elseif (($val instanceof Stringable) || method_exists($val, '__toString')) {
                    $val = (string)$val;
                } else {
                    $val = get_object_vars($val);
                }
            }

            if (is_array($val)) {
                $val = '[ ' . arrayToString($val) . ' ]';
            } elseif (is_bool($val)) {
                $val = true === $val ? 'true' : 'false';
            } else {
                $val = $val ?? 'None';
            }

            $list[] = sprintf("(%s: %s)", $key, $val);
        }

        return implode($separator, $list);
    }
}

if (!function_exists('commandContext')) {
    function commandContext(): string
    {
        if (inContainer()) {
            return sprintf('docker exec -ti %s console ', env('CONTAINER_NAME', 'watchstate'));
        }

        return ($_SERVER['argv'][0] ?? 'php console') . ' ';
    }
}

if (!function_exists('getAppVersion')) {
    function getAppVersion(): string
    {
        $version = Config::get('version', 'dev-master');

        if ('$(version_via_ci)' === $version) {
            $gitDir = ROOT_PATH . '/.git/';

            if (is_dir($gitDir)) {
                $cmd = 'git --git-dir=%1$s describe --exact-match --tags 2> /dev/null || git --git-dir=%1$s rev-parse --short HEAD';
                exec(sprintf($cmd, escapeshellarg($gitDir)), $output, $status);

                if (0 === $status) {
                    return $output[0] ?? 'dev-master';
                }
            }

            return 'dev-master';
        }

        return $version;
    }
}

if (!function_exists('t')) {
    function t($phrase, string|int ...$args): string
    {
        static $lang;

        if (null === $lang) {
            $lang = require __DIR__ . '/../../config/lang.php';
        }

        if (isset($lang[$phrase])) {
            throw new InvalidArgumentException(
                sprintf('Invalid language definition \'%s\' key was given.', $phrase)
            );
        }

        $text = $lang[$phrase];

        if (!empty($args)) {
            $text = sprintf($text, ...$args);
        }

        return $text;
    }
}


if (!function_exists('isValidName')) {
    /**
     * Allow only [Aa-Zz][0-9][_] in server names.
     *
     * @param string $name
     *
     * @return bool
     */
    function isValidName(string $name): bool
    {
        return 1 === preg_match('/^\w+$/', $name);
    }
}

if (false === function_exists('formatDuration')) {
    function formatDuration(int|float $milliseconds): string
    {
        $seconds = floor($milliseconds / 1000);
        $minutes = floor($seconds / 60);
        $hours = floor($minutes / 60);
        $seconds %= 60;
        $minutes %= 60;

        return sprintf('%02u:%02u:%02u', $hours, $minutes, $seconds);
    }
}

if (false === function_exists('array_keys_diff')) {
    /**
     * Return keys that match or does not match keys in list.
     *
     * @param array $base array containing all keys.
     * @param array $list list of keys that you want to filter based on.
     * @param bool $has Whether to get keys that exist in $list or exclude them.
     * @return array
     */
    function array_keys_diff(array $base, array $list, bool $has = true): array
    {
        return array_filter($base, fn($key) => $has === in_array($key, $list), ARRAY_FILTER_USE_KEY);
    }
}

if (false === function_exists('getMemoryUsage')) {
    function getMemoryUsage(): string
    {
        return fsize(memory_get_usage() - BASE_MEMORY);
    }
}

if (false === function_exists('getPeakMemoryUsage')) {
    function getPeakMemoryUsage(): string
    {
        return fsize(memory_get_peak_usage() - BASE_PEAK_MEMORY);
    }
}

if (false === function_exists('makeIgnoreId')) {
    function makeIgnoreId(string $url): UriInterface
    {
        static $filterQuery = null;

        if (null === $filterQuery) {
            $filterQuery = function (string $query): string {
                $list = $final = [];
                $allowed = ['id'];

                parse_str($query, $list);

                foreach ($list as $key => $val) {
                    if (empty($val) || false === in_array($key, $allowed)) {
                        continue;
                    }

                    $final[$key] = $val;
                }

                return http_build_query($final);
            };
        }

        $id = (new Uri($url))->withPath('')->withFragment('')->withPort(null);
        return $id->withQuery($filterQuery($id->getQuery()));
    }
}

if (false === function_exists('isIgnoredId')) {
    function isIgnoredId(
        string $backend,
        string $type,
        string $db,
        string|int $id,
        string|int|null $backendId = null
    ): bool {
        if (false === in_array($type, iFace::TYPES_LIST)) {
            throw new RuntimeException(sprintf('Invalid context type \'%s\' was given.', $type));
        }

        $list = Config::get('ignore', []);

        $key = makeIgnoreId(sprintf('%s://%s:%s@%s?id=%s', $type, $db, $id, $backend, $backendId));

        if (null !== ($list[(string)$key->withQuery('')] ?? null)) {
            return true;
        }

        if (null === $backendId) {
            return false;
        }

        return null !== ($list[(string)$key] ?? null);
    }
}

if (false === function_exists('r')) {
    /**
     * Substitute words enclosed in special tags for values from context.
     *
     * @param string $text text that contains tokens.
     * @param array $context A key/value pairs list.
     * @param string $tagLeft left tag bracket. Default '{'.
     * @param string $tagRight right tag bracket. Default '}'.
     *
     * @return string
     */
    function r(string $text, array $context = [], string $tagLeft = '{', string $tagRight = '}'): string
    {
        if (false === str_contains($text, $tagLeft) || false === str_contains($text, $tagRight)) {
            return $text;
        }

        $pattern = '#' . preg_quote($tagLeft, '#') . '([\w\d_.]+)' . preg_quote($tagRight, '#') . '#is';

        $status = preg_match_all($pattern, $text, $matches);

        if (false === $status || $status < 1) {
            return $text;
        }

        $replacements = [];

        foreach ($matches[1] as $key) {
            $placeholder = $tagLeft . $key . $tagRight;

            if (false === str_contains($text, $placeholder)) {
                continue;
            }

            if (false === ag_exists($context, $key)) {
                continue;
            }

            $val = ag($context, $key);

            $context = ag_delete($context, $key);

            if (is_null($val) || is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replacements[$placeholder] = $val;
            } elseif (is_object($val)) {
                $replacements[$placeholder] = implode(',', get_object_vars($val));
            } elseif (is_array($val)) {
                $replacements[$placeholder] = implode(',', $val);
            } else {
                $replacements[$placeholder] = '[' . gettype($val) . ']';
            }
        }

        return strtr($text, $replacements);
    }
}

if (false === function_exists('generateRoutes')) {
    function generateRoutes(): array
    {
        $dirs = [
            __DIR__ . '/../Commands',
        ];

        foreach (array_keys(Config::get('supported', [])) as $backend) {
            $dir = r(__DIR__ . '/../Backends/{backend}/Commands', ['backend' => ucfirst($backend)]);

            if (!file_exists($dir)) {
                continue;
            }

            $dirs[] = $dir;
        }

        $routes = (new Router($dirs))->generate();

        try {
            Container::get(CacheInterface::class)->set(
                'routes',
                $routes,
                new DateInterval('PT1H')
            );
        } catch (\Psr\SimpleCache\InvalidArgumentException) {
        }

        return $routes;
    }
}

if (!function_exists('getClientIp')) {
    function getClientIp(?ServerRequestInterface $request = null): string
    {
        $params = $request?->getServerParams() ?? $_SERVER;

        $realIp = (string)ag($params, 'REMOTE_ADDR', '0.0.0.0');

        if (false === (bool)Config::get('trust.proxy', false)) {
            return $realIp;
        }

        $forwardIp = ag(
            $params,
            'HTTP_' . strtoupper(trim(str_replace('-', '_', Config::get('trust.header', 'X-Forwarded-For'))))
        );

        if ($forwardIp === $realIp || empty($forwardIp)) {
            return $realIp;
        }

        if (null === ($firstIp = explode(',', $forwardIp)[0] ?? null)) {
            return $realIp;
        }

        $firstIp = trim($firstIp);

        if (false === filter_var($firstIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }

        return trim($firstIp);
    }
}

if (false === function_exists('inContainer')) {
    function inContainer(): bool
    {
        if (true === (bool)env('IN_CONTAINER')) {
            return true;
        }

        if (true === file_exists('/.dockerenv')) {
            return true;
        }

        return false;
    }
}
