<?php
declare(strict_types=1);

namespace Chronos\Application\Workflows;

use Chronos\Application\Shared\Dto;
use Chronos\Application\Shared\Input;

/** Application operations independent of HTTP requests and routing. */
final class WorkflowInput
{

    public static function metadata(array $body, int $nameMax = 250): array
    {
        $out=[];
        foreach (['name','description'] as $key) { if (array_key_exists($key,$body)) { $max=$key==='name'?$nameMax:65535; $out[$key]=Dto::storedText(Input::text($body[$key],$key,$max,$key==='name'),$key,$key==='name'?($nameMax===50?50:256):65535); } }
        return $out;
    }

    public static function jobStatus(mixed $status): int
    {
        if ((!is_int($status)&&!is_string($status))||!in_array((string)$status,['-2','-1','0','1','2','3'],true)){Input::invalid('status','Choose a supported job status.');}
        return (int)$status;
    }
}
