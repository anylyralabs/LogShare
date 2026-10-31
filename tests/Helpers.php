<?php

function makeZip(array $entries): string {
    $tmp = CORE_PATH . '/tmp/test_' . uniqid() . '.zip';
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $data = file_get_contents($tmp);
    unlink($tmp);
    return $data;
}
