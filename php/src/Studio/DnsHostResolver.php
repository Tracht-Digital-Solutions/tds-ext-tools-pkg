<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Studio;

/** The system resolver: A records via gethostbynamel, AAAA via dns_get_record. */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }
        if (function_exists('dns_get_record')) {
            $v6 = @dns_get_record($host, DNS_AAAA);
            if (is_array($v6)) {
                foreach ($v6 as $rec) {
                    if (isset($rec['ipv6']) && is_string($rec['ipv6'])) {
                        $ips[] = $rec['ipv6'];
                    }
                }
            }
        }
        return array_values(array_unique($ips));
    }
}
