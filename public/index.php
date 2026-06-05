<?php

@ini_set('upload_max_filesize', '100M');
@ini_set('post_max_size', '108M');
@ini_set('memory_limit', '512M');
use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
