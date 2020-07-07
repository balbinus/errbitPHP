<?php

namespace Errbit\Writer;

use Errbit\Exception\Notice;

class SocketWriter implements WriterInterface
{
    /**
     * Hoptoad Notifier Route
     */
    const NOTICES_PATH  = '/notifier_api/v2/notices/';

    /**
     * {@inheritdoc}
     */
    public function write($exception, array $config)
    {
        if (!$config['async']) {
            $socket = fsockopen(
                $this->buildConnectionScheme($config),
                $config['port'],
                $errno,
                $errstr,
                $config['connect_timeout']
            );

            if ($socket) {
                stream_set_timeout($socket, $config['write_timeout']);
                $payLoad = $this->buildPayload($exception, $config);
                fwrite($socket, $payLoad);
                fclose($socket);
            }
        } else {
            $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

            if ($socket) {
                socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, true);
                socket_set_option($socket, SOL_SOCKET, SO_REUSEPORT, true);
                socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, 50000);
                $sto_s = floor($config['write_timeout']);
                $sto_us = ($config['write_timeout'] - $sto_s) * 1000000;
                socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ["sec" => $sto_s, "usec" => $sto_us]);

                $payLoad = $this->buildPayload($exception, $config);

                // estimate the escaped payload size
                $payLoad_length = strlen($payLoad);
                $json_encoded_payload_length = strlen(json_encode($payLoad));
                $escaping_ratio = $json_encoded_payload_length * 1.05 / $payLoad_length;

                // generate a unique id for reassembly
                $messageId = sha1(uniqid('errbit-', true));

                $mtu = array_key_exists('mtu', $config) ? $config['mtu'] : 7000;
                $chunks = str_split($payLoad, floor($mtu / $escaping_ratio));
                foreach ($chunks as $idx => $chunk) {
                    $packet = array(
                        "messageid" => $messageId,
                        "data" => $chunk
                    );
                    if ($idx == count($chunks)-1) {
                        $packet['last'] = true;
                    }
                    $fragment = json_encode($packet);
                    socket_sendto($socket, $fragment, strlen($fragment), 0, $config['host'], $config['port']);
                }
                socket_close($socket);
            }
        }
    }

    protected function buildPayload($exception, $config)
    {
        return $this->addHttpHeadersIfNeeded(
            $this->buildNoticeFor($exception, $config),
            $config
        );
    }



    protected function buildConnectionScheme($config)
    {
        $proto = "";
        if ($config['async']) {
            $proto = "udp";
        } elseif ($config['secure']) {
             $proto = "ssl";
        } else {
            $proto = 'tcp';
        }

        return sprintf('%s://%s', $proto, $config['host']);
    }

    protected function addHttpHeadersIfNeeded($body, $config)
    {
        if ($config['async']) {
            return $body;
        } else {
            return sprintf(
                "%s\r\n\r\n%s",
                implode(
                    "\r\n",
                    array(
                        sprintf('POST %s HTTP/1.1', self::NOTICES_PATH),
                        sprintf('Host: %s', $config['host']),
                        sprintf('User-Agent: %s', $config['agent']),
                        sprintf('Content-Type: %s', 'text/xml'),
                        sprintf('Accept: %s', 'text/xml, application/xml'),
                        sprintf('Content-Length: %d', strlen($body)),
                        sprintf('Connection: %s', 'close')
                    )
                ),
                $body
            );
        }
    }

    protected function buildNoticeFor($exception, $options)
    {
        return Notice::forException($exception, $options)->asXml();
    }
}
