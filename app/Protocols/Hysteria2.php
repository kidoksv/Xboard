<?php

namespace App\Protocols;

use App\Models\Server;
use App\Support\AbstractProtocol;

class Hysteria2 extends AbstractProtocol
{
    public $flags = ['hy2', 'hysteria2'];

    public $allowedProtocols = [
        Server::TYPE_HYSTERIA,
    ];

    public function handle()
    {
        $user = $this->user;
        $uri = '';

        foreach ($this->servers as $server) {
            if ((int) data_get($server, 'protocol_settings.version', 2) !== 2) {
                continue;
            }
            $uri .= General::buildHysteria($server['password'], $server);
        }

        return response(base64_encode($uri))
            ->header('content-type', 'text/plain')
            ->header('subscription-userinfo', "upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}");
    }

    public static function encodeServers(array $servers): string
    {
        $uri = '';

        foreach ($servers as $server) {
            if (($server['type'] ?? null) !== Server::TYPE_HYSTERIA) {
                continue;
            }
            if ((int) data_get($server, 'protocol_settings.version', 2) !== 2) {
                continue;
            }
            $uri .= General::buildHysteria($server['password'], $server);
        }

        return base64_encode($uri);
    }
}
