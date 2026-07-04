<?php

namespace App\Support;

/**
 * Validação de URLs fornecidas por usuários antes de o servidor fazer requisições
 * a elas (proteção contra SSRF). Bloqueia esquemas não-HTTP e hosts que resolvem
 * para IPs privados, loopback, link-local ou metadata de nuvem.
 */
class SafeUrl
{
    /**
     * Retorna true apenas se a URL for http/https e o host resolver
     * exclusivamente para endereços IP públicos.
     */
    public static function isSafePublicUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'];

        // Coleta os IPs candidatos (o host pode já ser um IP literal).
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }

            // Fallback IPv4 caso dns_get_record não retorne nada útil.
            if (empty($ips)) {
                $resolved = gethostbyname($host);
                if ($resolved && $resolved !== $host) {
                    $ips[] = $resolved;
                }
            }
        }

        if (empty($ips)) {
            // Não foi possível resolver o host → trata como inseguro.
            return false;
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * True se o host (nome ou IP literal) resolver apenas para IPs públicos.
     * Use para validar destinos de conexões diretas (ex.: PDO/MySQL externo)
     * onde não há esquema http/https.
     */
    public static function isSafePublicHost(string $host): bool
    {
        $host = trim($host);
        if ($host === '') {
            return false;
        }

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
            if (empty($ips)) {
                $resolved = gethostbyname($host);
                if ($resolved && $resolved !== $host) {
                    $ips[] = $resolved;
                }
            }
        }

        if (empty($ips)) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * True se o IP for público (não privado, loopback, link-local nem reservado).
     */
    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // FILTER_FLAG_NO_PRIV_RANGE + NO_RES_RANGE cobrem RFC1918, loopback,
        // link-local (169.254/16, incl. metadata da nuvem 169.254.169.254),
        // e faixas reservadas, tanto IPv4 quanto IPv6.
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
