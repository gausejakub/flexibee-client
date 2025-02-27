<?php

declare(strict_types = 1);

namespace EcomailFlexibee\Http;

use EcomailFlexibee\Config;
use EcomailFlexibee\Http\Response\FlexibeeResponse;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use function basename;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function date;
use function dirname;
use function implode;
use function is_string;
use function mb_strlen;
use function microtime;
use function number_format;
use function sprintf;
use function trim;
use const CURLINFO_HTTP_CODE;
use const FILE_APPEND;

final class HttpClient
{

    private HttpCurlBuilder $httpCurlBuilder;

    public function __construct() {
        $this->httpCurlBuilder = new HttpCurlBuilder();
    }

    /**
     * @param array<mixed> $postFields
     * @param array<string> $queryParameters
     * @param array<string> $headers
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionFail
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeForbidden
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidAuthorization
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeMethodNotAllowed
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNotAcceptableRequest
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestFail
     */
    public function request(
        string $url,
        Method $httpMethod,
        array $postFields,
        array $queryParameters,
        array $headers,
        Config $config
    ): FlexibeeResponse
    {

        $ch = $this->httpCurlBuilder->build($url, $httpMethod, $postFields, $queryParameters, $headers, $config);

        $startTime = microtime(true);
        $output = curl_exec($ch);
        $responseTime = microtime(true) - $startTime;
        $output = is_string($output) ? $output : null;
        $errorMessage = mb_strlen(trim(curl_error($ch))) === 0
            ? null
            : curl_error($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($config->getLogFilePath() !== null) {
            $headersContents = ['EMPTY'];

            if (count($headers) > 0) {
                $headersContents = [];

                foreach ($headers as $key => $value) {
                    $headersContents[] = sprintf('%s:%s', $key, $value);
                }
            }

            $rootDir = dirname($config->getLogFilePath());
            $fileSystem = new Filesystem(new Local($rootDir, FILE_APPEND));
            $logContent = sprintf(
                '%s METHOD: %s URL:%s TIME:%s STATUS:%s HEADERS: %s',
                date('Y-m-d H:i:s'),
                $httpMethod->getValue(),
                $url,
                number_format($responseTime, 2),
                $statusCode,
                implode(',', $headersContents),
            );

            if ($errorMessage !== null) {
                $logContent = sprintf('%s ERROR:%s', $logContent, $errorMessage);
            }

            $logContent .= "\n";
            $fileSystem->put(
                basename($config->getLogFilePath()),
                $logContent,
            );
        }

        return ResponseFactory::createFromOutput(
            $url,
            $httpMethod,
            $output,
            $statusCode,
            curl_errno($ch),
            $errorMessage,
        );
    }

}
