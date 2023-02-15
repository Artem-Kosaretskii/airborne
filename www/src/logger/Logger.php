<?php
declare(strict_types=1);

namespace Airborne;

class Logger
{
    private string $log_path;
    private mixed $ip;
    private int|false $pid;

    /**
     * @param string $log_path
     */
    public function __construct(string $log_path)
    {
        $this->log_path = $log_path;
        $this->ip = $this->get_ip();
        $this->pid = getmypid();
    }

    public function log($str, $data = []) {
        $log_path = $this->log_path;
        $this->logging($log_path, $str);
        if ($data) {
            $this->logging($log_path, $data);
        }
    }

    private function logging($filename, $str)
    {
        $exists = true;
        if (!file_exists($filename)) {
            $exists = false;
            if (!file_exists($this->get_directory($filename))) {
                mkdir($this->get_directory($filename), 0777, true);
                chmod($this->get_directory($filename), 0777);
            }
        }
        if ($fp=fopen($filename, "a")) {
            $s = date("Y.m.d H:i:s")." ip:".$this->ip.' pid:'. $this->pid.' ';
            if (is_array($str) || is_object($str)) $str = json_encode($str,64|128|256);
            $s .= " ".$str."\r\n";
            fwrite($fp, $s);
            fclose($fp);
        }
        if (!$exists) {
            chmod($filename, 0777);
        }
    }

    private function get_directory($path): string
    {
        $path = str_replace('\\', '/', $path);
        $p = mb_strrpos($path, "/");
        if ($p === false) {
            return '';
        } else {
            return mb_substr($path, 0, $p+1);
        }
    }

    public function get_ip()
    {
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER["HTTP_X_REAL_IP"];
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])){
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])){
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        }
        return $ip;
    }
}