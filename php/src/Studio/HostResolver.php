<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Studio;

/** Resolves a host name to every IPv4 and IPv6 address it currently has. */
interface HostResolver
{
    /** @return list<string> */
    public function resolve(string $host): array;
}
