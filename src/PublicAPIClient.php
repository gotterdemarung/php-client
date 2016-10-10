<?php

namespace Covery\Client;

use Covery\Client\Envelopes\ValidatorV1;
use Covery\Client\Requests\Decision;
use Covery\Client\Requests\Event;
use Covery\Client\Requests\Ping;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class PublicAPIClient
{
    /**
     * @var CredentialsInterface
     */
    private $credentials;

    /**
     * @var TransportInterface
     */
    private $transport;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ValidatorV1
     */
    private $validator;

    /**
     * Client constructor.
     * @param CredentialsInterface $credentials
     * @param TransportInterface $transport
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        CredentialsInterface $credentials,
        TransportInterface $transport,
        LoggerInterface $logger = null
    ) {
        $this->credentials = $credentials;
        $this->transport = $transport;
        $this->logger = $logger === null ? new NullLogger() : $logger;
        $this->validator = new ValidatorV1();
    }

    private function send(RequestInterface $request)
    {
        $request = $this->credentials->signRequest($request);
        try {
            $this->logger->info('Sending request to ' . $request->getUri());
            $before = microtime(true);
            $response = $this->transport->send($request);
            $this->logger->info(sprintf('Request done in %.2f', microtime(true) - $before));
        } catch (\Exception $inner) {
            $this->logger->error($inner->getMessage(), ['exception' => $inner]);
            // Wrapping exception
            throw new Exception('Error sending request', 0, $inner);
        }
        $code = $response->getStatusCode();
        $this->logger->debug('Received status code ' . $code);

        if ($code >= 400) {
            // Analyzing response
            if ($response->hasHeader('X-Maxwell-Status') && $response->hasHeader('X-Maxwell-Error-Message')) {
                // Extended data available
                $message = $response->getHeaderLine('X-Maxwell-Error-Message');
                $type = $response->getHeaderLine('X-Maxwell-Error-Type');
                if (strpos($type, 'AuthorizationRequiredException') !== false) {
                    $this->logger->error('Authentication failure ' . $message);
                    throw new AuthException($message, $code);
                }

                switch ($message) {
                    case 'Empty auth token':
                    case 'Empty signature':
                    case 'Empty nonce':
                        $this->logger->error('Authentication failure ' . $message);
                        throw new AuthException($message, $code);
                }

                $this->logger->error('Covery error ' . $message);
                throw new DeliveredException($message, $code);
            }


            throw new Exception("Communication failed with status code {$code}");
        }

        return $response->getBody()->getContents();
    }

    /**
     * Utility method, that reads JSON data
     *
     * @param $string
     * @return mixed|null
     * @throws Exception
     */
    private function readJson($string)
    {
        if (!is_string($string)) {
            throw new Exception("Unable to read JSON - not a string received");
        }
        if (strlen($string) === 0) {
            return null;
        }

        $data = json_decode($string, true);
        if ($data === null) {
            $message = 'Unable to decode JSON';
            if (function_exists('json_last_error_msg')) {
                $message = json_last_error_msg();
            }

            throw new Exception($message);
        }

        return $data;
    }

    /**
     * Sends request to Covery and returns access level, associated with
     * used credentials
     *
     * This method can be used for Covery health check and availability
     * On any problem (network, credentials, server side) this method
     * will throw an exception
     *
     * @return string
     * @throws Exception
     */
    public function ping()
    {
        $data = $this->readJson($this->send(new Ping()));
        if (!is_array($data) || !isset($data['level'])) {
            throw new Exception("Malformed response");
        }

        return $data['level'];
    }

    /**
     * Sends envelope to Covery and returns it's ID on Covery side
     * Before sending, validation is performed
     *
     * @param EnvelopeInterface $envelope
     * @return int
     * @throws Exception
     */
    public function sendEvent(EnvelopeInterface $envelope)
    {
        // Validating
        $this->validator->validate($envelope);

        // Sending
        $data = $this->readJson($this->send(new Event($envelope)));

        if (!is_array($data) || !isset($data['requestId']) || !is_int($data['requestId'])) {
            throw new Exception("Malformed response");
        }

        return $data['requestId'];
    }

    /**
     * Sends envelope to Covery for analysis
     *
     * @param EnvelopeInterface $envelope
     * @return Result
     * @throws Exception
     */
    public function makeDecision(EnvelopeInterface $envelope)
    {
        // Validating
        $this->validator->validate($envelope);

        // Sending
        $data = $this->readJson($this->send(new Decision($envelope)));

        if (!is_array($data)) {
            throw new Exception("Malformed response");
        }

        try {
            return new Result(
                $data['requestId'],
                $data['score'],
                $data['accept'],
                $data['reject'],
                $data['manual']
            );
        } catch (\Exception $error) {
            throw new Exception('Malformed response', 0, $error);
        }
    }
}