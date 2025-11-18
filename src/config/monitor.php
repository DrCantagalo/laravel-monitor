<?php

use Illuminate\Support\Facades\File;

$json = storage_path('monitor/installation.json');
$data = File::exists($json) ? json_decode(File::get($json), true) : [];
return [

    'installation_hash' => $data['installation_hash'] ?? null,
    'local_token'       => $data['local_token'] ?? null,
    'external_token'       => $data['external_token'] ?? null,
    'installation_code'       => $data['installation_code'] ?? null,
    'installed_at'       => $data['installed_at'] ?? null,
    'version' => '0.1.0',

];
