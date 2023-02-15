<?php
declare(strict_types=1);

namespace Airborne;

class Cache
{

private string $path;

public function __construct()
{
    $this->path = __DIR__. '/../../data/';
}

public function set($name, $data, $ttl)
{
    $file = $this->path . $name . '.cache';
    file_put_contents($file,json_encode([$name=>$data, 'ttl'=>time()+$ttl]));
}

public function get($name):int
{
    $file = $this->path . $name . '.cache';
    if (file_exists($file)){
        $cache = json_decode(file_get_contents($this->path . $name . '.cache'),true);
        return $cache['ttl'] > time() ? $cache[$name] : 0;
    } else {
        return 0;
    }
}


}