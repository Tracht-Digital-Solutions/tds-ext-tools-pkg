<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Studio;

/** Performs ONE GET to a pre-validated address. Never follows redirects. */
interface HttpTransport
{
    /**
     * @param string $pinnedIp the address the connection MUST use (already checked public)
     * @return array{status:int,headers:array<string,string>,body:string,truncated:bool,error:?string}
     */
    public function get(string $url, string $host, string $pinnedIp, int $maxBytes, int $timeoutSeconds): array;
}
