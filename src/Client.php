<?php

declare(strict_types=1);

namespace FeWeDev\CurlFtp;

use Exception;
use FeWeDev\Base\Arrays;
use FeWeDev\Base\Variables;

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2024 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */
class Client
{
    /** @var Variables */
    protected $variables;

    /** @var Arrays */
    protected $arrays;

    /** @var resource */
    private $curlHandle;

    /** @var string */
    private $hostName;

    /** @var string */
    private $path = '/';

    /** @var bool */
    private $useSsl = false;

    /**
     * @param Variables|null $variables
     * @param Arrays|null    $arrays
     */
    public function __construct(Variables $variables = null, Arrays $arrays = null)
    {
        if ($variables === null) {
            $variables = new Variables();
        }

        if ($arrays === null) {
            $arrays = new Arrays($variables);
        }

        $this->variables = $variables;
        $this->arrays = $arrays;
    }

    /**
     * @return void
     */
    public function __destruct()
    {
        if ($this->curlHandle !== null) {
            curl_close($this->curlHandle);
        }
    }

    /**
     * @throws Exception
     */
    public function open(array $args = [])
    {
        $hostName = $this->arrays->getValue(
            $args,
            'host'
        );

        if ($this->variables->isEmpty($hostName)) {
            throw new Exception('The specified host is empty. Set the host and try again.');
        }

        $port = $this->arrays->getValue(
            $args,
            'port',
            21
        );
        $userName = $this->arrays->getValue(
            $args,
            'user',
            'anonymous'
        );
        $password = $this->arrays->getValue(
            $args,
            'password',
            $userName === 'anonymous' ? 'anonymous@noserver.com' : ''
        );
        $useTls = $this->arrays->getValue(
            $args,
            'tls',
            false
        );
        $useSsl = $this->arrays->getValue(
            $args,
            'ssl',
            false
        );
        $usePassiveMode = $this->arrays->getValue(
            $args,
            'passive',
            false
        );
        $timeout = $this->arrays->getValue(
            $args,
            'timeout',
            30
        );

        $this->connect(
            $hostName,
            $port,
            $userName,
            $password,
            $useTls,
            $useSsl,
            $usePassiveMode,
            $timeout
        );
    }

    /**
     * @throws Exception
     */
    public function connect(
        string $hostName,
        int $port,
        string $userName,
        string $password,
        bool $useTls = false,
        bool $useSsl = false,
        bool $usePassiveMode = true,
        int $timeout = 30
    ) {
        $this->curlHandle = @curl_init();

        if ($this->curlHandle === false) {
            throw new Exception('Could not initialize curl');
        }

        $this->hostName = $hostName;
        $this->useSsl = $useSsl;

        $options = [
            CURLOPT_PORT           => $port,
            CURLOPT_USERPWD        => sprintf(
                '%s:%s',
                $userName,
                $password
            ),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HEADER         => false,
            CURLOPT_UPLOAD         => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true
        ];

        if ($useTls || $useSsl) {
            $options[ CURLOPT_USE_SSL ] = CURLFTPSSL_ALL;
            $options[ CURLOPT_SSL_VERIFYPEER ] = false;
            $options[ CURLOPT_SSL_VERIFYHOST ] = false;
            $options[ CURLOPT_FTPSSLAUTH ] = $useTls ? CURLFTPAUTH_TLS : CURLFTPAUTH_SSL;
        }

        if (! $usePassiveMode) {
            $options[ CURLOPT_FTPPORT ] = '-';
        }

        foreach ($options as $key => $value) {
            $this->setCurlOption(
                $key,
                $value
            );
        }

        $this->executeCurl();
    }

    /**
     * @return void
     */
    public function close()
    {
        $this->curlHandle = null;
        $this->hostName = null;
        $this->path = '/';
        $this->useSsl = false;
    }

    protected function getUrl(?string $fileName = null): string
    {
        return sprintf(
            '%s://%s/%s',
            $this->useSsl ? 'ftps' : 'ftp',
            $this->hostName,
            $this->getPath($fileName)
        );
    }

    protected function getPath(?string $fileName = null): string
    {
        return sprintf(
            '%s/%s',
            trim(
                $this->path,
                '/'
            ),
            $this->variables->isEmpty($fileName) ? '' : $fileName
        );
    }

    public function cd(string $path)
    {
        $this->path = $path;
    }

    /**
     * @throws Exception
     */
    public function ls(): array
    {
        $result = $this->executeCurl();

        if ($this->variables->isEmpty($result)) {
            return [];
        }

        $files = explode(
            "\n",
            trim($result)
        );

        $list = [];

        foreach ($files as $file) {
            $list[] = [
                'text' => $file,
                'id'   => sprintf(
                    '%s/%s',
                    rtrim(
                        $this->path,
                        '/'
                    ),
                    $file
                )
            ];
        }

        return $list;
    }

    /**
     * @return string|bool
     * @throws Exception
     */
    public function read(string $fileName)
    {
        return $this->executeCurl($fileName);
    }

    /**
     * @return string|bool
     * @throws Exception
     */
    public function rm(string $fileName)
    {
        $this->setCurlOption(
            CURLOPT_QUOTE,
            [
                sprintf(
                    'DELE /%s',
                    ltrim(
                        $this->getPath($fileName),
                        '/'
                    )
                )
            ]
        );

        $result = $this->executeCurl();

        $this->setCurlOption(
            CURLOPT_QUOTE,
            []
        );

        return $result;
    }

    /**
     * @throws Exception
     */
    public function write(string $fileName, string $src): void
    {
        $tempDirectory = sys_get_temp_dir();

        $tempFileName = tempnam(
            $tempDirectory,
            'foo'
        );

        file_put_contents(
            $tempFileName,
            $src
        );

        $tempFileHandle = fopen(
            $tempFileName,
            'r'
        );

        $this->setCurlOption(
            CURLOPT_UPLOAD,
            true
        );
        $this->setCurlOption(
            CURLOPT_INFILE,
            $tempFileHandle
        );
        $this->setCurlOption(
            CURLOPT_INFILESIZE,
            filesize($tempFileName)
        );

        $this->executeCurl($fileName);

        $this->setCurlOption(
            CURLOPT_UPLOAD,
            false
        );
        $this->setCurlOption(
            CURLOPT_INFILE,
            null
        );
        $this->setCurlOption(
            CURLOPT_INFILESIZE,
            null
        );

        fclose($tempFileHandle);
    }

    /**
     * @throws Exception
     */
    protected function setCurlOption(int $key, $value): void
    {
        $optionResult = @curl_setopt(
            $this->curlHandle,
            $key,
            $value
        );

        if (! $optionResult) {
            throw new Exception(
                sprintf(
                    'Could not set curl option with key: %s (%d)',
                    $key,
                    curl_errno($this->curlHandle)
                )
            );
        }
    }

    /**
     * @return string|bool
     * @throws Exception
     */
    protected function executeCurl(?string $fileName = null)
    {
        $this->setCurlOption(
            CURLOPT_FTPLISTONLY,
            $this->variables->isEmpty($fileName)
        );
        $this->setCurlOption(
            CURLOPT_URL,
            $this->getUrl($fileName)
        );

        $result = @curl_exec($this->curlHandle);

        if ($result === false) {
            throw new Exception(
                sprintf(
                    'Could not handle content in path: %s (%d: %s)',
                    $this->getPath($fileName),
                    curl_errno($this->curlHandle),
                    curl_error($this->curlHandle)
                )
            );
        }

        return $result;
    }
}
