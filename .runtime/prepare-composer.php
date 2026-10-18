<?php

$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root.'/composer.json'), true);
$manifest['config']['vendor-dir'] = $root.'/vendor';
foreach (['autoload', 'autoload-dev'] as $section) {
    foreach ($manifest[$section]['psr-4'] ?? [] as $namespace => $path) {
        $manifest[$section]['psr-4'][$namespace] = $root.'/'.$path;
    }
}
$lock = json_decode(file_get_contents($root.'/composer.lock'), true);
foreach (['packages', 'packages-dev'] as $section) {
    foreach ($lock[$section] as &$package) {
        if (isset($package['dist']['url'])) {
            $package['dist']['url'] = preg_replace(
                '~https://api.github.com/repos/([^/]+)/([^/]+)/zipball/(.+)~',
                'https://codeload.github.com/$1/$2/legacy.zip/$3',
                $package['dist']['url']
            );
        }
    }
    unset($package);
}
file_put_contents(__DIR__.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents(__DIR__.'/composer.lock', json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
