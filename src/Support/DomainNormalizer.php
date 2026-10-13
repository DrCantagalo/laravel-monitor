<?php

namespace Drcantagalo\LaravelMonitor\Support;

/**
 * laravel-monitor 345: normaliza a resposta de "domínio principal do
 * site" pedida por `monitor:install`/`monitor:update` (`main_domain` em
 * `storage/monitor/installation.json`) — aceita o mesmo texto livre que
 * `ask_url` aceitava antes (URL completa, com ou sem esquema/porta/path)
 * e devolve só o host puro, sem `www.`, em minúsculas. `null` quando o
 * resultado não é um host válido, pra quem chama perguntar de novo em
 * vez de gravar lixo que o runtime (`VisitorOriginDetector`) usaria pra
 * sempre a partir daí.
 */
class DomainNormalizer
{
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return null;
        }

        return $host;
    }
}
