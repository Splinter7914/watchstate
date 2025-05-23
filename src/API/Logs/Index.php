<?php

declare(strict_types=1);

namespace App\API\Logs;

use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Route;
use App\Libs\Config;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Stream;
use App\Libs\StreamedBody;
use App\Libs\Traits\APITraits;
use finfo;
use LimitIterator;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Random\RandomException;
use SplFileObject;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class Index
{
    use APITraits;

    public const string URL = '%{api.prefix}/logs';
    public const string URL_FILE = '%{api.prefix}/log';
    private const int MAX_LIMIT = 100;

    private int $counter = 1;
    private array $users = [];

    public function __construct(iImport $mapper, iLogger $logger)
    {
        $this->users = array_keys(getUsersContext(mapper: $mapper, logger: $logger));
    }

    #[Get(self::URL . '[/]', name: 'logs')]
    public function logsList(iRequest $request): iResponse
    {
        $path = fixPath(Config::get('tmpDir') . '/logs');

        $list = [];

        $apiUrl = $request->getUri()->withHost('')->withPort(0)->withScheme('');
        parse_str($apiUrl->getquery(), $query);
        $query['stream'] = 1;
        $query = http_build_query($query);

        foreach (glob($path . '/*.*.log') as $file) {
            preg_match('/(\w+)\.(\w+)\.log/i', basename($file), $matches);

            $builder = [
                'filename' => basename($file),
                'type' => $matches[1] ?? '??',
                'date' => $matches[2] ?? '??',
                'size' => filesize($file),
                'modified' => makeDate(filemtime($file)),
            ];

            $list[] = $builder;
        }

        return api_response(Status::OK, $list);
    }

    /**
     * @throws RandomException
     */
    #[Get(Index::URL . '/recent[/]', name: 'logs.recent')]
    public function recent(iRequest $request): iResponse
    {
        $path = fixPath(Config::get('tmpDir') . '/logs');

        $list = [];

        $today = makeDate()->format('Ymd');

        $params = DataUtil::fromArray($request->getQueryParams());
        $limit = (int)$params->get('limit', 50);
        $limit = $limit < 1 ? 50 : $limit;

        foreach (glob($path . '/*.*.log') as $file) {
            preg_match('/(\w+)\.(\w+)\.log/i', basename($file), $matches);

            $logDate = $matches[2] ?? null;

            if (!$logDate || $logDate !== $today) {
                continue;
            }

            $builder = [
                'filename' => basename($file),
                'type' => $matches[1] ?? '??',
                'date' => $matches[2] ?? '??',
                'size' => filesize($file),
                'modified' => makeDate(filemtime($file)),
                'lines' => [],
            ];

            $file = new SplFileObject($file, 'r');

            if ($file->getSize() > 1) {
                $file->seek(PHP_INT_MAX);
                $lastLine = $file->key();
                $it = new LimitIterator($file, max(0, $lastLine - $limit), $lastLine);
                foreach ($it as $line) {
                    $line = trim((string)$line);
                    if (empty($line)) {
                        continue;
                    }

                    $builder['lines'][] = self::formatLog($line, $this->users);
                }
            }

            $list[] = $builder;
        }

        return api_response(Status::OK, $list, headers: [
            'X-No-AccessLog' => '1'
        ]);
    }

    /**
     * @throws RandomException
     */
    #[Route(['GET', 'DELETE'], Index::URL_FILE . '/{filename}[/]', name: 'logs.view')]
    public function logView(iRequest $request, array $args = []): iResponse
    {
        if (null === ($filename = ag($args, 'filename'))) {
            return api_error('Invalid value for filename path parameter.', Status::BAD_REQUEST);
        }

        $path = realpath(fixPath(Config::get('tmpDir') . '/logs'));

        $filePath = realpath($path . '/' . $filename);

        if (false === $filePath) {
            return api_error('File not found.', Status::NOT_FOUND);
        }

        if (false === str_starts_with($filePath, $path)) {
            return api_error('Invalid file path.', Status::BAD_REQUEST);
        }

        if ('DELETE' === $request->getMethod()) {
            unlink($filePath);
            return api_response(Status::OK);
        }

        $params = DataUtil::fromArray($request->getQueryParams());

        $file = new SplFileObject($filePath, 'r');

        if (true === (bool)$params->get('download')) {
            return $this->download($filePath);
        }
        if ($params->get('stream')) {
            return $this->stream($filePath);
        }

        if (0 === ($offset = (int)$params->get('offset', 0)) || $offset < 0) {
            $offset = self::MAX_LIMIT;
        }

        if ($file->getSize() < 1) {
            return api_response(Status::OK, [
                'filename' => basename($filePath),
                'offset' => $offset,
                'next' => null,
                'max' => 0,
                'lines' => [],
            ]);
        }

        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();

        if ($offset === self::MAX_LIMIT && self::MAX_LIMIT >= $lastLine) {
            $offset = $lastLine;
        }

        $data = [
            'filename' => basename($filePath),
            'offset' => $offset,
            'next' => null,
            'max' => $lastLine,
            'lines' => [],
        ];

        if ($offset <= $lastLine) {
            $start = max(0, $lastLine - $offset);
            $it = new LimitIterator($file, $start, self::MAX_LIMIT);

            foreach ($it as $line) {
                $data['lines'][] = self::formatLog(trim((string)$line), $this->users);
            }

            $hasMore = $lastLine > $offset;
            $data['next'] = $hasMore ? min($offset + self::MAX_LIMIT, $lastLine) : null;
        }

        return api_response(Status::OK, $data, headers: ['X-No-AccessLog' => '1']);
    }

    private function download(string $filePath): iResponse
    {
        $mime = new finfo(FILEINFO_MIME_TYPE)->file($filePath);

        return api_response(Status::OK, Stream::make($filePath, 'r'), headers: [
            'Content-Type' => false === $mime ? 'application/octet-stream' : $mime,
            'Content-Length' => filesize($filePath),
        ]);
    }

    private function stream(string $filePath): iResponse
    {
        ini_set('max_execution_time', '3601');

        $callable = function () use ($filePath) {
            ignore_user_abort(true);

            try {
                $cmd = 'exec tail -n 0 -F ' . escapeshellarg($filePath);

                $process = Process::fromShellCommandline($cmd);
                $process->setTimeout(3600);

                $process->start(callback: function ($type, $data) use ($process) {
                    echo "event: data\n";
                    $data = trim((string)$data);
                    echo implode(
                        PHP_EOL,
                        array_map(
                            function ($data) {
                                if (!is_string($data)) {
                                    return null;
                                }
                                return 'data: ' . json_encode(self::formatLog(trim($data), $this->users));
                            },
                            (array)preg_split("/\R/", $data)
                        )
                    );
                    echo "\n\n";

                    flush();

                    $this->counter = 3;

                    if (ob_get_length() > 0) {
                        ob_end_flush();
                    }

                    if (connection_aborted()) {
                        $process->stop(1, 9);
                    }
                });

                while ($process->isRunning()) {
                    sleep(1);
                    $this->counter--;

                    if ($this->counter > 1) {
                        continue;
                    }

                    $this->counter = 3;

                    echo "event: ping\n";
                    echo 'data: ' . makeDate() . "\n\n";
                    flush();

                    if (ob_get_length() > 0) {
                        ob_end_flush();
                    }

                    if (connection_aborted()) {
                        $process->stop(1, 9);
                    }
                }
            } catch (ProcessTimedOutException) {
            }

            return '';
        };

        return api_response(Status::OK, StreamedBody::create($callable), headers: [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Format log line.
     *
     * @param string $line
     * @param array $users
     *
     * @return array
     * @throws RandomException
     */
    public static function formatLog(string $line, array $users = []): array
    {
        if (empty($line)) {
            return [
                'id' => md5((string)(hrtime(true) + random_int(1, 10000))),
                'item_id' => null,
                'user' => null,
                'backend' => null,
                'date' => null,
                'text' => $line
            ];
        }

        $dateRegex = '/^\[([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?[+-][0-9]{2}:[0-9]{2})]/i';

        $dateMatch = preg_match($dateRegex, $line, $matches);
        $idMatch = preg_match("/'#(?P<item_id>\d+):/", $line, $idMatches);
        $identMatch = preg_match("/'((?P<client>\w+):\s)?(?P<user>\w+)@(?P<backend>\w+)'/i", $line, $identMatches);

        $logLine = [
            'id' => md5($line . hrtime(true) + random_int(1, 10000)),
            'item_id' => null,
            'user' => null,
            'backend' => null,
            'date' => 1 === $dateMatch ? $matches[1] : null,
            'text' => 1 === $dateMatch ? trim(preg_replace($dateRegex, '', $line)) : $line,
        ];

        if (1 === $idMatch) {
            $logLine['item_id'] = $idMatches['item_id'];
        }

        if (1 === $identMatch && in_array($identMatches['user'], $users, true)) {
            $logLine['user'] = $identMatches['user'];
            $logLine['backend'] = $identMatches['backend'];
        }

        return $logLine;
    }
}
