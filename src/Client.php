<?php

namespace SergiX44\Gradio;

use InvalidArgumentException;
use SergiX44\Gradio\Client\Endpoint;
use SergiX44\Gradio\Client\RemoteClient;
use SergiX44\Gradio\DTO\Config;
use SergiX44\Gradio\DTO\Messages\Estimation;
use SergiX44\Gradio\DTO\Messages\Message;
use SergiX44\Gradio\DTO\Messages\ProcessCompleted;
use SergiX44\Gradio\DTO\Messages\ProcessGenerating;
use SergiX44\Gradio\DTO\Messages\ProcessStarts;
use SergiX44\Gradio\DTO\Messages\QueueFull;
use SergiX44\Gradio\DTO\Messages\SendData;
use SergiX44\Gradio\DTO\Messages\SendHash;
use SergiX44\Gradio\DTO\Output;
use SergiX44\Gradio\Event\Event;
use SergiX44\Gradio\Exception\GradioException;
use SergiX44\Gradio\Exception\QueueFullException;

class Client extends RemoteClient
{
    private const HTTP_PREDICT = 'run/predict';

    private const QUEUE_JOIN = 'queue/join';

    private const SSE_GET_DATA = 'queue/data';

    private const HTTP_CONFIG = 'config';

    protected Config $config;

    private string $sessionHash;

    private array $endpoints = [];

    private ?string $hfToken;

    public function __construct(string $src, ?string $hfToken = null, ?Config $config = null)
    {
        parent::__construct($src);
        $this->config = $config ?? $this->http('get', self::HTTP_CONFIG, dto: Config::class);
        $this->loadEndpoints($this->config->dependencies);
        $this->sessionHash = substr(md5(microtime()), 0, 11);
        $this->hfToken = $hfToken;
    }

    protected function loadEndpoints(array $dependencies): void
    {
        foreach ($dependencies as $index => $dp) {
            $endpoint = new Endpoint($this->config, $index, $dp);
            $this->endpoints[$index] = $endpoint;
            if ($endpoint->apiName() !== null) {
                $this->endpoints[$endpoint->apiName()] = $endpoint;
            }
        }
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function predict(array $arguments, ?string $apiName = null, ?int $fnIndex = null, bool $raw = false): Output|array|null
    {
        if ($apiName === null && $fnIndex === null) {
            throw new InvalidArgumentException('You must provide an apiName or fnIndex');
        }

        $apiName = $apiName !== null ? str_replace('/', '', $apiName) : null;
        $endpoint = $this->endpoints[$apiName ?? $fnIndex] ?? null;

        if ($endpoint === null) {
            throw new InvalidArgumentException('Endpoint not found');
        }

        return $this->submit($endpoint, $arguments, $raw);
    }

    protected function submit(Endpoint $endpoint, array $arguments, bool $raw): Output|array|null
    {
        $payload = $this->preparePayload($arguments);
        $this->fireEvent(Event::SUBMIT, $payload);

        if ($endpoint->skipsQueue()) {
            return $this->http('post', $endpoint->uri(), [
                'data' => $payload,
                'fn_index' => $endpoint->index,
                'session_hash' => $this->sessionHash,
            ], dto: $raw ? null : Output::class);
        }

        return match ($this->config->protocol) {
            'sse_v1', 'sse_v2' => $this->sseV1V2Loop($endpoint, $payload),
            default => $this->websocketLoop($endpoint, $payload),
        };
    }

    private function preparePayload(array $arguments): array
    {
        return array_map(static function ($arg) {
            if (is_resource($arg)) {
                $filename = stream_get_meta_data($arg)['uri'];
                $contents = stream_get_contents($filename);
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->buffer($contents);

                return [
                    'data' => "data:$mime;base64,".base64_encode($contents),
                    'name' => basename($filename),
                ];
            }

            if (is_string($arg) && file_exists($arg)) {
                $contents = file_get_contents($arg);
                $mime = mime_content_type($arg);

                return [
                    'data' => "data:$mime;base64,".base64_encode($contents),
                    'name' => basename($arg),
                ];
            }

            return $arg;
        }, $arguments);
    }

    /**
     * @throws GradioException
     * @throws QueueFullException
     * @throws \JsonException
     */
    private function websocketLoop(Endpoint $endpoint, array $payload): ?Output
    {
        $ws = $this->ws(self::QUEUE_JOIN);

        while (true) {
            $data = $ws->receive();

            // why sometimes $data is null?
            if ($data === null) {
                continue;
            }

            $message = $this->hydrator->hydrateWithJson(Message::class, $data);

            if ($message instanceof SendHash) {
                $ws->sendJson([
                    'fn_index' => $endpoint->index,
                    'session_hash' => $this->sessionHash,
                ]);
            } elseif ($message instanceof QueueFull) {
                $this->fireEvent(Event::QUEUE_FULL, [$message]);
                $ws->close();
                throw new QueueFullException();
            } elseif ($message instanceof Estimation) {
                $this->fireEvent(Event::QUEUE_ESTIMATION, [$message]);
            } elseif ($message instanceof SendData) {
                $ws->sendJson([
                    'fn_index' => $endpoint->index,
                    'session_hash' => $this->sessionHash,
                    'data' => $payload,
                    'event_data' => null,
                ]);
            } elseif ($message instanceof ProcessCompleted) {
                $this->fireEvent(Event::PROCESS_COMPLETED, [$message]);
                if ($message->success) {
                    $this->fireEvent(Event::PROCESS_SUCCESS, [$message]);
                } else {
                    $this->fireEvent(Event::PROCESS_FAILED, [$message]);
                }
                break;
            } elseif ($message instanceof ProcessStarts) {
                $this->fireEvent(Event::PROCESS_STARTS, [$message]);
            } elseif ($message instanceof ProcessGenerating) {
                $this->fireEvent(Event::PROCESS_GENERATING, [$message]);
            } else {
                throw new GradioException("'Unknown message type $data");
            }
        }

        $ws->close();

        return $message?->output;
    }

    private function sseV1V2Loop(Endpoint $endpoint, array $payload): ?Output
    {
        $response = $this->httpRaw('post', self::QUEUE_JOIN, [
            'data' => $payload,
            'fn_index' => $endpoint->index,
            'session_hash' => $this->sessionHash,
        ]);

        if ($response->getStatusCode() === 503) {
            throw new QueueFullException();
        }

        if ($response->getStatusCode() !== 200) {
            throw new GradioException('Error joining the queue');
        }

        //        $data = $this->decodeResponse($response);
        //        $eventId = $data['event_id'];

        $response = $this->httpRaw('get', self::SSE_GET_DATA, ['session_hash' => $this->sessionHash], [
            'headers' => [
                'Accept' => 'text/event-stream',
            ],
            'stream' => true,
        ]);

        $buffer = '';
        $message = null;
        while (! $response->getBody()->eof()) {
            $data = $response->getBody()->read(1);
            if ($data !== "\n") {
                $buffer .= $data;

                continue;
            }

            // read second \n
            $response->getBody()->read(1);

            // remove data:
            $buffer = str_replace('data: ', '', $buffer);
            $message = $this->hydrator->hydrateWithJson(Message::class, $buffer);

            if ($message instanceof ProcessCompleted) {
                $this->fireEvent(Event::PROCESS_COMPLETED, [$message]);
                if ($message->success) {
                    $this->fireEvent(Event::PROCESS_SUCCESS, [$message]);
                } else {
                    $this->fireEvent(Event::PROCESS_FAILED, [$message]);
                }
                break;
            } elseif ($message instanceof ProcessStarts) {
                $this->fireEvent(Event::PROCESS_STARTS, [$message]);
            } elseif ($message instanceof ProcessGenerating) {
                $this->fireEvent(Event::PROCESS_GENERATING, [$message]);
            } elseif ($message instanceof Estimation) {
                $this->fireEvent(Event::QUEUE_ESTIMATION, [$message]);
            }

            $buffer = '';
        }

        return $message?->output;
    }
}
