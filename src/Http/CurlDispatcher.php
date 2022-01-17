<?php
declare(strict_types = 1);

namespace Embed\Http;

use Composer\CaBundle\CaBundle;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Class to fetch html pages
 */
final class CurlDispatcher
{
    private RequestInterface $request;
    private $curl;
    private array $headers = [];
    private $isBinary = false;
    private $body;
    private ?int $error = null;
    private array $settings;

    /**
     * @return ResponseInterface[]
     */
    public static function fetch(array $settings, ResponseFactoryInterface $responseFactory, RequestInterface ...$requests): array
    {
        if (count($requests) === 1) {
            $connection = new static($settings, $requests[0]);
            return [$connection->exec($responseFactory)];
        }

        //Init connections
        $multi = curl_multi_init();
        $connections = [];

        foreach ($requests as $request) {
            $connection = new static($settings, $request);
            curl_multi_add_handle($multi, $connection->curl);

            $connections[] = $connection;
        }

        //Run
        $active = null;
        do {
            $status = curl_multi_exec($multi, $active);

            if ($active) {
                curl_multi_select($multi);
            }

            $info = curl_multi_info_read($multi);

            if ($info) {
                foreach ($connections as $connection) {
                    if ($connection->curl === $info['handle']) {
                        $connection->result = $info['result'];
                        break;
                    }
                }
            }
        } while ($active && $status == CURLM_OK);

        //Close connections
        foreach ($connections as $connection) {
            curl_multi_remove_handle($multi, $connection->curl);
        }

        curl_multi_close($multi);

        return array_map(
            fn ($connection) => $connection->exec($responseFactory),
            $connections
        );
    }

    private function __construct(array $settings, RequestInterface $request)
    {
        $this->request = $request;
        $this->curl = curl_init((string) $request->getUri());
        $this->settings = $settings;

        $cookies = $settings['cookies_path'] ?? str_replace('//', '/', sys_get_temp_dir().'/embed-cookies.txt');

         $curlOptions = [
            CURLOPT_HTTPHEADER     => $this->getRequestHeaders(),
            CURLOPT_POST           => strtoupper($request->getMethod()) === 'POST',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_CAINFO         => CaBundle::getSystemCaRootBundlePath(),
            CURLOPT_AUTOREFERER    => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_USERAGENT      => $request->getHeaderLine('User-Agent'),
            CURLOPT_COOKIEJAR      => $cookies,
            CURLOPT_COOKIEFILE     => $cookies,
            CURLOPT_HEADERFUNCTION => [$this, 'writeHeader'],
            CURLOPT_WRITEFUNCTION  => [$this, 'writeBody'],
        ];

        // Overwrite the defaults with the curl options
        foreach ($curlOptions as $curlOptionKey => $curlOptionValue) {
            $curlSetting = $curlOptionValue;

            // Allow null as value to unset
            try {
                $curlSetting = $this->settings[$curlOptionKey];
            }   catch (\Exception $e) {}

            if ($curlSetting === 'unset' || $curlSetting === null) {
                unset($curlOptions[$curlOptionKey]);
            } else {
                $curlOptions[$curlOptionKey] = $curlSetting;
            }
        }

        curl_setopt_array($this->curl, $curlOptions);
    }

    private function exec(ResponseFactoryInterface $responseFactory): ResponseInterface
    {
        curl_exec($this->curl);

        $info = curl_getinfo($this->curl);

        if ($this->error) {
            $this->error(curl_strerror($this->error), $this->error);
        }

        if (curl_errno($this->curl)) {
            $this->error(curl_error($this->curl), curl_errno($this->curl));
        }

        curl_close($this->curl);

        $response = $responseFactory->createResponse($info['http_code']);

        foreach ($this->headers as $header) {
            list($name, $value) = $header;
            $response = $response->withAddedHeader($name, $value);
        }

        $response = $response
            ->withAddedHeader('Content-Location', $info['url'])
            ->withAddedHeader('X-Request-Time', sprintf('%.3f ms', $info['total_time']));

        if ($this->body) {
            //5Mb max
            $response->getBody()->write(stream_get_contents($this->body, 5000000, 0));
        }

        return $response;
    }

    private function error(string $message, int $code)
    {
        $ignored = $this->settings['ignored_errors'] ?? null;

        if ($ignored === true || (is_array($ignored) && in_array($code, $ignored))) {
            return;
        }

        if ($this->isBinary && $code === CURLE_WRITE_ERROR) {
            // The write callback aborted the request to prevent a download of the binary file
            return;
        }

        throw new NetworkException($message, $code, $this->request);
    }

    private function getRequestHeaders(): array
    {
        $headers = [];

        foreach ($this->request->getHeaders() as $name => $values) {
            switch (strtolower($name)) {
                case 'user-agent':
                break;
                default:
                $headers[] = $name . ':' . implode(', ', $values);
            }
        }

        return $headers;
    }

    private function writeHeader($curl, $string): int
    {
        if (preg_match('/^([\w-]+):(.*)$/', $string, $matches)) {
            $name = strtolower($matches[1]);
            $value = trim($matches[2]);
            $this->headers[] = [$name, $value];

            if ($name === 'content-type') {
                $this->isBinary = !preg_match('/(text|html|json)/', strtolower($value));
            }
        } elseif ($this->headers) {
            $key = array_key_last($this->headers);
            $this->headers[$key][1] .= ' '.trim($string);
        }

        return strlen($string);
    }

    private function writeBody($curl, $string): int
    {
        if ($this->isBinary) {
            return -1;
        }

        if (!$this->body) {
            $this->body = fopen('php://temp', 'w+');
        }

        return fwrite($this->body, $string);
    }
}
