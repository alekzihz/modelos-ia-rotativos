<?php

declare(strict_types=1);

namespace App\Modelos;

use RuntimeException;

final class IAServiceRotator
{
    /** @var \App\Modelos\IAService[] */
    private array $services;
    private string $stateFile;

    /** @param \App\Modelos\IAService[] $services */
    public function __construct(array $services, string $stateFile = 'ai_rr_index.txt')
    {
        if (!$services)
            throw new RuntimeException('No hay servicios registrados.');
        $this->services = array_values($services);
        $stateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $stateFile;
        $this->stateFile = $stateFile;
    }

    public function next(): IAService
    {
        $fh = fopen($this->stateFile, 'c+');
        if (!$fh)
            throw new RuntimeException('No puedo abrir: ' . $this->stateFile);

        flock($fh, LOCK_EX);

        rewind($fh);
        $raw = stream_get_contents($fh);
        $idx = (is_string($raw) && trim($raw) !== '') ? (int) trim($raw) : 0;

        $service = $this->services[$idx % count($this->services)];

        $idx = ($idx + 1) % count($this->services);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) $idx);
        fflush($fh);

        flock($fh, LOCK_UN);
        fclose($fh);

        return $service;
    }
}
